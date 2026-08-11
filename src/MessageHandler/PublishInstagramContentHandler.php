<?php

namespace App\MessageHandler;

use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Entity\MediaAsset;
use App\Enum\InstagramPublicationBatchStatus;
use App\Enum\InstagramPublicationStatus;
use App\Message\PublishInstagramContent;
use App\Repository\InstagramPublicationBatchRepository;
use App\Repository\InstagramPublicationMediaRepository;
use App\Repository\InstagramPublicationRepository;
use App\Service\Instagram\InstagramContainerStatus;
use App\Service\Instagram\InstagramPublisher;
use App\Service\Instagram\InstagramPublisherException;
use App\Service\Instagram\InstagramPublisherFailure;
use App\Service\Media\MediaDeletionService;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * @phpstan-type BatchReference array{id: int, position: int}
 * @phpstan-type PublicationClaim array{
 *     publicationId: int,
 *     sourceType: string,
 *     sourceId: int,
 *     totalMediaCount: int,
 *     totalBatchCount: int,
 *     batches: list<BatchReference>
 * }
 * @phpstan-type MediaWork array{
 *     id: int,
 *     position: int,
 *     publicUrl: string,
 *     containerId: string|null
 * }
 * @phpstan-type BatchWork array{
 *     id: int,
 *     position: int,
 *     caption: string|null,
 *     containerId: string|null,
 *     media: list<MediaWork>
 * }
 * @phpstan-type BatchClaim array{owned: bool, work: BatchWork|null}
 * @phpstan-type Completion array{
 *     publishedMediaCount: int,
 *     totalMediaCount: int,
 *     publishedBatchCount: int,
 *     totalBatchCount: int,
 *     publicationStatus: string
 * }
 */
#[AsMessageHandler]
final class PublishInstagramContentHandler
{
    private const PROCESSING_LEASE_MINUTES = 30;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InstagramPublicationRepository $publicationRepository,
        private readonly InstagramPublicationBatchRepository $batchRepository,
        private readonly InstagramPublicationMediaRepository $mediaRepository,
        private readonly InstagramPublisher $publisher,
        private readonly MediaDeletionService $mediaDeletionService,
        private readonly LoggerInterface $logger,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function __invoke(PublishInstagramContent $message): void
    {
        $claim = $this->claimPublication($message);
        if (null === $claim) {
            return;
        }

        if ([] === $claim['batches']) {
            $this->finalizePublicationWithoutWork($claim, $message->executionId);

            return;
        }

        foreach ($claim['batches'] as $batchReference) {
            $batchClaim = $this->claimBatch(
                $claim['publicationId'],
                $batchReference['id'],
                $message->executionId,
            );

            if (!$batchClaim['owned']) {
                $this->logOwnershipLost($claim, $batchReference);

                return;
            }

            $batch = $batchClaim['work'];
            if (null === $batch) {
                continue;
            }

            try {
                $completion = $this->publishBatch($claim, $batch, $message->executionId);
            } catch (InstagramPublisherException $exception) {
                $failureCode = $this->failureCode($exception);
                $failure = $this->recordBatchFailure(
                    $claim['publicationId'],
                    $batch['id'],
                    $message->executionId,
                    $exception->getMessage(),
                    $failureCode,
                    $exception->failure,
                );

                if (null === $failure) {
                    $this->logOwnershipLost($claim, $batchReference);

                    return;
                }

                $this->logger->error('Échec d’un lot de publication Instagram.', [
                    ...$this->publicationLogContext($claim),
                    'batch_id' => $batch['id'],
                    'batch_position' => $batch['position'],
                    'batch_media_count' => count($batch['media']),
                    'published_media_count' => $failure['publishedMediaCount'],
                    'total_media_count' => $failure['totalMediaCount'],
                    'published_batch_count' => $failure['publishedBatchCount'],
                    'total_batch_count' => $failure['totalBatchCount'],
                    'error_code' => $failureCode,
                    'http_status' => $exception->httpStatus,
                    'meta_error_code' => $exception->metaErrorCode,
                    'meta_error_subcode' => $exception->metaErrorSubcode,
                ]);

                $this->throwMessengerFailure($exception);
            }

            if (null === $completion) {
                $this->logOwnershipLost($claim, $batchReference);

                return;
            }

            $this->logger->info('Lot Instagram publié.', [
                ...$this->publicationLogContext($claim),
                'batch_id' => $batch['id'],
                'batch_position' => $batch['position'],
                'batch_media_count' => count($batch['media']),
                'container_id' => $completion['containerId'],
                'instagram_media_id' => $completion['instagramMediaId'],
                'recovered_from_published_container' => $completion['recovered'],
                'published_media_count' => $completion['counters']['publishedMediaCount'],
                'total_media_count' => $completion['counters']['totalMediaCount'],
                'published_batch_count' => $completion['counters']['publishedBatchCount'],
                'total_batch_count' => $completion['counters']['totalBatchCount'],
            ]);
        }
    }

    /**
     * @return PublicationClaim|null
     */
    private function claimPublication(PublishInstagramContent $message): ?array
    {
        return $this->entityManager->wrapInTransaction(function () use ($message): ?array {
            $publication = $this->lockedPublication($message->publicationId);
            if (null === $publication) {
                $this->logger->warning('Publication Instagram introuvable, message ignoré.', [
                    'publication_id' => $message->publicationId,
                ]);

                return null;
            }

            if ($publication->isTerminal()) {
                $this->logger->info('Publication Instagram terminale, message ignoré.', [
                    'publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                    'status' => $publication->getStatus()->value,
                ]);

                return null;
            }

            $now = $this->currentTime();
            $processingToken = $publication->getProcessingToken();
            $sameExecution = $processingToken === $message->executionId;
            if (null !== $processingToken
                && !$sameExecution
                && !$this->hasStaleLease($publication, $now)
            ) {
                $this->logger->info('Publication Instagram déjà revendiquée, message concurrent ignoré.', [
                    'publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                    'status' => $publication->getStatus()->value,
                ]);

                return null;
            }

            if (null !== $processingToken && !$sameExecution) {
                $this->logger->warning('Reprise d’une publication Instagram dont le verrou applicatif a expiré.', [
                    'publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                ]);
            } elseif ($sameExecution) {
                $this->logger->info('Reprise d’une exécution Instagram après redelivery Messenger.', [
                    'publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                ]);
            }

            $publication
                ->setProcessingToken($message->executionId)
                ->setStatus(InstagramPublicationStatus::Processing)
                ->clearLastError()
                ->registerAttempt($now);

            $batchReferences = [];
            foreach ($this->batchRepository->findForPublicationOrdered($publication) as $batch) {
                if (InstagramPublicationBatchStatus::Published === $batch->getStatus()) {
                    continue;
                }

                $batchId = $batch->getId();
                if (null === $batchId) {
                    throw new LogicException('Un lot Instagram persisté doit posséder un identifiant.');
                }

                $batchReferences[] = [
                    'id' => $batchId,
                    'position' => $batch->getPosition(),
                ];
            }

            $publication->synchronizeCounters();

            $publicationId = $publication->getId();
            if (null === $publicationId) {
                throw new LogicException('Une publication Instagram persistée doit posséder un identifiant.');
            }

            $this->logger->info('Publication Instagram revendiquée.', [
                'publication_id' => $publicationId,
                'source_type' => $publication->getSourceType()->value,
                'source_id' => $publication->getSourceId(),
                'total_media_count' => $publication->getTotalMediaCount(),
                'total_batch_count' => $publication->getTotalBatchCount(),
            ]);

            return [
                'publicationId' => $publicationId,
                'sourceType' => $publication->getSourceType()->value,
                'sourceId' => $publication->getSourceId(),
                'totalMediaCount' => $publication->getTotalMediaCount(),
                'totalBatchCount' => $publication->getTotalBatchCount(),
                'batches' => $batchReferences,
            ];
        });
    }

    /**
     * @return BatchClaim
     */
    private function claimBatch(int $publicationId, int $batchId, string $executionId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($publicationId, $batchId, $executionId): array {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return ['owned' => false, 'work' => null];
            }

            $batch = $this->batchRepository->find($batchId);
            if (!$batch instanceof InstagramPublicationBatch) {
                throw new LogicException('Le lot Instagram revendiqué est introuvable.');
            }

            $this->entityManager->refresh($batch, LockMode::PESSIMISTIC_WRITE);
            if ($batch->getPublication()?->getId() !== $publicationId) {
                throw new LogicException('Le lot Instagram ne correspond pas à sa publication.');
            }

            if (InstagramPublicationBatchStatus::Published === $batch->getStatus()) {
                return ['owned' => true, 'work' => null];
            }

            $now = $this->currentTime();
            $publication->setLastAttemptAt($now);
            $batch
                ->setStatus(InstagramPublicationBatchStatus::Processing)
                ->clearLastError()
                ->registerAttempt($now);

            $mediaWork = [];
            $mediaEntities = $this->mediaRepository->findForBatchOrdered($batch);
            foreach ($mediaEntities as $media) {
                $this->entityManager->refresh($media, LockMode::PESSIMISTIC_WRITE);
                $mediaId = $media->getId();
                if (null === $mediaId) {
                    throw new LogicException('Un média Instagram persisté doit posséder un identifiant.');
                }

                $mediaWork[] = [
                    'id' => $mediaId,
                    'position' => $media->getBatchPosition(),
                    'publicUrl' => $media->getPublicUrl(),
                    'containerId' => $media->getContainerId(),
                ];
            }

            $batch->synchronizeMediaCount();

            return [
                'owned' => true,
                'work' => [
                    'id' => $batchId,
                    'position' => $batch->getPosition(),
                    'caption' => $batch->getCaption() ?? $publication->getCaption(),
                    'containerId' => $batch->getContainerId(),
                    'media' => $mediaWork,
                ],
            ];
        });
    }

    /**
     * @param PublicationClaim $claim
     * @param BatchWork        $batch
     *
     * @return array{
     *     containerId: string,
     *     instagramMediaId: string|null,
     *     recovered: bool,
     *     counters: Completion
     * }|null
     */
    private function publishBatch(array $claim, array $batch, string $executionId): ?array
    {
        $mediaCount = count($batch['media']);
        if ($mediaCount < 1 || $mediaCount > 10) {
            throw InstagramPublisherException::invalidArgument();
        }

        if (1 === $mediaCount) {
            return $this->publishSingleMediaBatch($claim, $batch, $executionId);
        }

        return $this->publishCarouselBatch($claim, $batch, $executionId);
    }

    /**
     * @param PublicationClaim $claim
     * @param BatchWork        $batch
     *
     * @return array{
     *     containerId: string,
     *     instagramMediaId: string|null,
     *     recovered: bool,
     *     counters: Completion
     * }|null
     */
    private function publishSingleMediaBatch(array $claim, array $batch, string $executionId): ?array
    {
        $media = $batch['media'][0];
        $containerId = $batch['containerId'] ?? $media['containerId'];

        if (null === $containerId) {
            $containerId = $this->publisher->createImageContainer(
                $media['publicUrl'],
                $batch['caption'],
                false,
            );

            if (!$this->checkpointMediaContainer(
                $claim['publicationId'],
                $batch['id'],
                $media['id'],
                $executionId,
                $containerId,
                true,
            )) {
                return null;
            }

            $this->logger->info('Conteneur image Instagram enregistré.', [
                ...$this->publicationLogContext($claim),
                'batch_id' => $batch['id'],
                'batch_position' => $batch['position'],
                'container_id' => $containerId,
            ]);
        } elseif (null === $batch['containerId'] || null === $media['containerId']) {
            if (!$this->checkpointMediaContainer(
                $claim['publicationId'],
                $batch['id'],
                $media['id'],
                $executionId,
                $containerId,
                true,
            )) {
                return null;
            }
        }

        $status = $this->publisher->waitUntilReady($containerId);
        if (InstagramContainerStatus::Published === $status) {
            $counters = $this->completeBatch(
                $claim['publicationId'],
                $batch['id'],
                $executionId,
                null,
            );

            return null === $counters ? null : [
                'containerId' => $containerId,
                'instagramMediaId' => null,
                'recovered' => true,
                'counters' => $counters,
            ];
        }

        $instagramMediaId = $this->publisher->publishContainer($containerId);
        $counters = $this->completeBatch(
            $claim['publicationId'],
            $batch['id'],
            $executionId,
            $instagramMediaId,
        );

        return null === $counters ? null : [
            'containerId' => $containerId,
            'instagramMediaId' => $instagramMediaId,
            'recovered' => false,
            'counters' => $counters,
        ];
    }

    /**
     * @param PublicationClaim $claim
     * @param BatchWork        $batch
     *
     * @return array{
     *     containerId: string,
     *     instagramMediaId: string|null,
     *     recovered: bool,
     *     counters: Completion
     * }|null
     */
    private function publishCarouselBatch(array $claim, array $batch, string $executionId): ?array
    {
        $parentContainerId = $batch['containerId'];

        if (null === $parentContainerId) {
            $childContainerIds = [];
            foreach ($batch['media'] as $media) {
                $childContainerId = $media['containerId'];
                if (null === $childContainerId) {
                    $childContainerId = $this->publisher->createImageContainer(
                        $media['publicUrl'],
                        null,
                        true,
                    );

                    if (!$this->checkpointMediaContainer(
                        $claim['publicationId'],
                        $batch['id'],
                        $media['id'],
                        $executionId,
                        $childContainerId,
                        false,
                    )) {
                        return null;
                    }

                    $this->logger->info('Conteneur enfant Instagram enregistré.', [
                        ...$this->publicationLogContext($claim),
                        'batch_id' => $batch['id'],
                        'batch_position' => $batch['position'],
                        'media_position' => $media['position'],
                        'container_id' => $childContainerId,
                    ]);
                }

                $childStatus = $this->publisher->waitUntilReady($childContainerId);
                if (InstagramContainerStatus::Published === $childStatus) {
                    // A carousel child only becomes PUBLISHED through its parent. This closes
                    // the response-loss window without creating a duplicate parent post.
                    $counters = $this->completeBatch(
                        $claim['publicationId'],
                        $batch['id'],
                        $executionId,
                        null,
                    );

                    return null === $counters ? null : [
                        'containerId' => $childContainerId,
                        'instagramMediaId' => null,
                        'recovered' => true,
                        'counters' => $counters,
                    ];
                }

                $childContainerIds[] = $childContainerId;
            }

            $parentContainerId = $this->publisher->createCarouselContainer(
                $childContainerIds,
                $batch['caption'] ?? '',
            );

            if (!$this->checkpointBatchContainer(
                $claim['publicationId'],
                $batch['id'],
                $executionId,
                $parentContainerId,
            )) {
                return null;
            }

            $this->logger->info('Conteneur carrousel Instagram enregistré.', [
                ...$this->publicationLogContext($claim),
                'batch_id' => $batch['id'],
                'batch_position' => $batch['position'],
                'container_id' => $parentContainerId,
            ]);
        }

        $parentStatus = $this->publisher->waitUntilReady($parentContainerId);
        if (InstagramContainerStatus::Published === $parentStatus) {
            $counters = $this->completeBatch(
                $claim['publicationId'],
                $batch['id'],
                $executionId,
                null,
            );

            return null === $counters ? null : [
                'containerId' => $parentContainerId,
                'instagramMediaId' => null,
                'recovered' => true,
                'counters' => $counters,
            ];
        }

        $instagramMediaId = $this->publisher->publishContainer($parentContainerId);
        $counters = $this->completeBatch(
            $claim['publicationId'],
            $batch['id'],
            $executionId,
            $instagramMediaId,
        );

        return null === $counters ? null : [
            'containerId' => $parentContainerId,
            'instagramMediaId' => $instagramMediaId,
            'recovered' => false,
            'counters' => $counters,
        ];
    }

    private function checkpointMediaContainer(
        int $publicationId,
        int $batchId,
        int $mediaId,
        string $executionId,
        string $containerId,
        bool $alsoBatchContainer,
    ): bool {
        return $this->entityManager->wrapInTransaction(function () use (
            $publicationId,
            $batchId,
            $mediaId,
            $executionId,
            $containerId,
            $alsoBatchContainer,
        ): bool {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return false;
            }
            $checkpointedAt = $this->currentTime();
            $publication->setLastAttemptAt($checkpointedAt);

            $batch = $this->lockedBatch($batchId, $publicationId);
            $media = $this->mediaRepository->find($mediaId);
            if (!$media instanceof InstagramPublicationMedia) {
                throw new LogicException('Le média Instagram à enregistrer est introuvable.');
            }

            $this->entityManager->refresh($media, LockMode::PESSIMISTIC_WRITE);
            if ($media->getBatch()?->getId() !== $batchId) {
                throw new LogicException('Le média Instagram ne correspond pas à son lot.');
            }

            $media
                ->setContainerId($containerId)
                ->setContainerCreatedAt($checkpointedAt)
                ->clearLastError();

            if ($alsoBatchContainer) {
                $batch->setContainerId($containerId);
            }

            return true;
        });
    }

    private function checkpointBatchContainer(
        int $publicationId,
        int $batchId,
        string $executionId,
        string $containerId,
    ): bool {
        return $this->entityManager->wrapInTransaction(function () use (
            $publicationId,
            $batchId,
            $executionId,
            $containerId,
        ): bool {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return false;
            }
            $publication->setLastAttemptAt($this->currentTime());

            $this->lockedBatch($batchId, $publicationId)->setContainerId($containerId);

            return true;
        });
    }

    /**
     * @return Completion|null
     */
    private function completeBatch(
        int $publicationId,
        int $batchId,
        string $executionId,
        ?string $instagramMediaId,
    ): ?array {
        /** @var array{counters: Completion, releasedMediaAssets: list<MediaAsset>}|null $result */
        $result = $this->entityManager->wrapInTransaction(function () use (
            $publicationId,
            $batchId,
            $executionId,
            $instagramMediaId,
        ): ?array {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return null;
            }

            $batch = $this->lockedBatch($batchId, $publicationId);
            $now = $this->currentTime();
            $publication->setLastAttemptAt($now);
            $batch
                ->setStatus(InstagramPublicationBatchStatus::Published)
                ->setPublishedAt($batch->getPublishedAt() ?? $now)
                ->clearLastError();

            if (null !== $instagramMediaId) {
                $batch->setInstagramMediaId($instagramMediaId);
            }

            $media = $this->mediaRepository->findForBatchOrdered($batch);
            $releasedMediaAssets = [];
            $seenMediaAssets = [];
            foreach ($media as $item) {
                $this->entityManager->refresh($item, LockMode::PESSIMISTIC_WRITE);
                $mediaAsset = $item->getMediaAsset();
                if ($mediaAsset instanceof MediaAsset) {
                    $mediaAssetKey = $mediaAsset->getId() ?? spl_object_id($mediaAsset);
                    if (!isset($seenMediaAssets[$mediaAssetKey])) {
                        $seenMediaAssets[$mediaAssetKey] = true;
                        $releasedMediaAssets[] = $mediaAsset;
                    }
                }

                $item->releaseMediaAsset()->clearLastError();
            }
            $batch->setMediaCount(count($media));

            $publication->synchronizeCounters()->clearLastError();
            if ($publication->getTotalBatchCount() > 0
                && $publication->getPublishedBatchCount() === $publication->getTotalBatchCount()
            ) {
                $publication
                    ->setStatus(InstagramPublicationStatus::Published)
                    ->setPublishedAt($publication->getPublishedAt() ?? $now)
                    ->clearProcessingToken();
            } else {
                $publication->setStatus(InstagramPublicationStatus::Processing);
            }

            return [
                'counters' => $this->completionCounters($publication),
                'releasedMediaAssets' => $releasedMediaAssets,
            ];
        });

        if (null === $result) {
            return null;
        }

        // The batch is durably Published before cleanup starts. A cleanup failure must
        // never make Messenger republish an already successful Instagram batch.
        $this->cleanupReleasedMediaAssets($publicationId, $batchId, $result['releasedMediaAssets']);

        return $result['counters'];
    }

    /**
     * @return Completion|null
     */
    private function recordBatchFailure(
        int $publicationId,
        int $batchId,
        string $executionId,
        string $message,
        string $errorCode,
        InstagramPublisherFailure $failure,
    ): ?array {
        return $this->entityManager->wrapInTransaction(function () use (
            $publicationId,
            $batchId,
            $executionId,
            $message,
            $errorCode,
            $failure,
        ): ?array {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return null;
            }

            $batch = $this->lockedBatch($batchId, $publicationId);
            if (in_array($failure, [
                InstagramPublisherFailure::ContainerError,
                InstagramPublisherFailure::ContainerExpired,
            ], true)) {
                // ERROR/EXPIRED containers can never become publishable again. Clear
                // the parent and every child so a bounded retry builds a fresh graph.
                $batch->setContainerId(null);
                foreach ($this->mediaRepository->findForBatchOrdered($batch) as $media) {
                    $this->entityManager->refresh($media, LockMode::PESSIMISTIC_WRITE);
                    $media
                        ->setContainerId(null)
                        ->setContainerCreatedAt(null);
                }
            }

            $batch
                ->setStatus(InstagramPublicationBatchStatus::Failed)
                ->setLastError($message)
                ->setLastErrorCode($errorCode);

            $publication->synchronizeCounters();
            $publication
                ->setStatus(
                    $publication->getPublishedBatchCount() > 0
                        ? InstagramPublicationStatus::PartialFailure
                        : InstagramPublicationStatus::Failed,
                )
                ->setLastError($message)
                ->setLastErrorCode($errorCode)
                ->clearProcessingToken();

            return $this->completionCounters($publication);
        });
    }

    /**
     * @param PublicationClaim $claim
     */
    private function finalizePublicationWithoutWork(array $claim, string $executionId): void
    {
        $completion = $this->entityManager->wrapInTransaction(function () use ($claim, $executionId): ?array {
            $publication = $this->lockedOwnedPublication($claim['publicationId'], $executionId);
            if (null === $publication) {
                return null;
            }

            $now = $this->currentTime();
            $publication->synchronizeCounters()->clearLastError();

            if (0 === $publication->getTotalBatchCount()) {
                foreach ($this->mediaRepository->findForPublicationOrdered($publication) as $media) {
                    $this->entityManager->refresh($media, LockMode::PESSIMISTIC_WRITE);
                    $media->releaseMediaAsset();
                }

                $publication
                    ->setStatus(InstagramPublicationStatus::NoMedia)
                    ->clearProcessingToken();
            } elseif ($publication->getPublishedBatchCount() === $publication->getTotalBatchCount()) {
                $publication
                    ->setStatus(InstagramPublicationStatus::Published)
                    ->setPublishedAt($publication->getPublishedAt() ?? $now)
                    ->clearProcessingToken();
            } else {
                $publication
                    ->setStatus(
                        $publication->getPublishedBatchCount() > 0
                            ? InstagramPublicationStatus::PartialFailure
                            : InstagramPublicationStatus::Failed,
                    )
                    ->setLastError('Aucun lot Instagram exécutable n’a été trouvé.')
                    ->setLastErrorCode('handler_no_work')
                    ->clearProcessingToken();
            }

            return $this->completionCounters($publication);
        });

        if (null !== $completion) {
            $this->logger->info('Publication Instagram finalisée sans appel Meta.', [
                ...$this->publicationLogContext($claim),
                'status' => $completion['publicationStatus'],
                'published_media_count' => $completion['publishedMediaCount'],
                'total_media_count' => $completion['totalMediaCount'],
                'published_batch_count' => $completion['publishedBatchCount'],
                'total_batch_count' => $completion['totalBatchCount'],
            ]);
        }
    }

    private function lockedPublication(int $publicationId): ?InstagramPublication
    {
        $publication = $this->publicationRepository->findOneForUpdate($publicationId);
        if (!$publication instanceof InstagramPublication) {
            return null;
        }

        return $publication;
    }

    private function lockedOwnedPublication(int $publicationId, string $executionId): ?InstagramPublication
    {
        $publication = $this->lockedPublication($publicationId);

        return null !== $publication && $publication->getProcessingToken() === $executionId
            ? $publication
            : null;
    }

    private function lockedBatch(int $batchId, int $publicationId): InstagramPublicationBatch
    {
        $batch = $this->batchRepository->find($batchId);
        if (!$batch instanceof InstagramPublicationBatch) {
            throw new LogicException('Le lot Instagram est introuvable.');
        }

        $this->entityManager->refresh($batch, LockMode::PESSIMISTIC_WRITE);
        if ($batch->getPublication()?->getId() !== $publicationId) {
            throw new LogicException('Le lot Instagram ne correspond pas à sa publication.');
        }

        return $batch;
    }

    private function hasStaleLease(InstagramPublication $publication, DateTimeImmutable $now): bool
    {
        $lastAttemptAt = $publication->getLastAttemptAt();
        if (null === $lastAttemptAt) {
            // A token without its claim timestamp cannot prove a live owner. Treat the
            // inconsistent row like the recovery command does and take it over safely.
            return true;
        }

        $cutoff = $now->sub(new DateInterval(sprintf('PT%dM', self::PROCESSING_LEASE_MINUTES)));

        return $lastAttemptAt < $cutoff;
    }

    private function currentTime(): DateTimeImmutable
    {
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new LogicException('L’horloge Instagram doit retourner une date immuable.');
        }

        return $now;
    }

    private function failureCode(InstagramPublisherException $exception): string
    {
        $parts = [$exception->failure->value];

        if (null !== $exception->httpStatus) {
            $parts[] = 'http_'.$exception->httpStatus;
        }

        if (null !== $exception->metaErrorCode) {
            $parts[] = 'meta_'.$exception->metaErrorCode;
        }

        if (null !== $exception->metaErrorSubcode) {
            $parts[] = 'sub_'.$exception->metaErrorSubcode;
        }

        return implode(':', $parts);
    }

    private function throwMessengerFailure(InstagramPublisherException $exception): never
    {
        if ($this->isTransient($exception)) {
            throw new RecoverableMessageHandlingException(
                'La publication Instagram a rencontré une erreur transitoire.',
                forceRetry: false,
            );
        }

        throw new UnrecoverableMessageHandlingException(
            'La publication Instagram a rencontré une erreur permanente.',
        );
    }

    /**
     * @param list<MediaAsset> $mediaAssets
     */
    private function cleanupReleasedMediaAssets(int $publicationId, int $batchId, array $mediaAssets): void
    {
        if ([] === $mediaAssets) {
            return;
        }

        try {
            foreach ($mediaAssets as $mediaAsset) {
                $this->mediaDeletionService->deleteIfOrphan($mediaAsset);
            }

            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->warning('Nettoyage post-publication des médias Instagram incomplet.', [
                'publication_id' => $publicationId,
                'batch_id' => $batchId,
                'media_count' => count($mediaAssets),
                'error_code' => 'media_cleanup_failed',
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function isTransient(InstagramPublisherException $exception): bool
    {
        if (in_array($exception->failure, [
            InstagramPublisherFailure::Transport,
            InstagramPublisherFailure::PollingTimeout,
            InstagramPublisherFailure::InvalidResponse,
            InstagramPublisherFailure::ContainerExpired,
        ], true)) {
            return true;
        }

        return InstagramPublisherFailure::Http === $exception->failure
            && null !== $exception->httpStatus
            && (429 === $exception->httpStatus
                || ($exception->httpStatus >= 500 && $exception->httpStatus < 600));
    }

    /**
     * @return Completion
     */
    private function completionCounters(InstagramPublication $publication): array
    {
        return [
            'publishedMediaCount' => $publication->getPublishedMediaCount(),
            'totalMediaCount' => $publication->getTotalMediaCount(),
            'publishedBatchCount' => $publication->getPublishedBatchCount(),
            'totalBatchCount' => $publication->getTotalBatchCount(),
            'publicationStatus' => $publication->getStatus()->value,
        ];
    }

    /**
     * @param PublicationClaim $claim
     *
     * @return array{publication_id: int, source_type: string, source_id: int}
     */
    private function publicationLogContext(array $claim): array
    {
        return [
            'publication_id' => $claim['publicationId'],
            'source_type' => $claim['sourceType'],
            'source_id' => $claim['sourceId'],
        ];
    }

    /**
     * @param PublicationClaim $claim
     * @param BatchReference   $batch
     */
    private function logOwnershipLost(array $claim, array $batch): void
    {
        $this->logger->info('Traitement Instagram interrompu après perte du verrou applicatif.', [
            ...$this->publicationLogContext($claim),
            'batch_id' => $batch['id'],
            'batch_position' => $batch['position'],
        ]);
    }
}
