<?php

namespace App\Service\Instagram;

use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Enum\InstagramPublicationBatchStatus;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Message\PublishInstagramContent;
use App\Repository\InstagramPublicationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class InstagramPublicationScheduler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InstagramPublicationRepository $publicationRepository,
        private InstagramMediaCollector $mediaCollector,
        private InstagramMediaBatcher $mediaBatcher,
        private InstagramCaptionBuilder $captionBuilder,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates the immutable publication snapshot inside the Estela publication transaction.
     * The caller must only invoke this method for a freshly observed non-public -> public transition.
     */
    public function prepareFirstPublication(HikeDraft|CityVisitDraft $content): ?InstagramPublication
    {
        $sourceId = $content->getId();
        if ($sourceId === null) {
            throw new \LogicException('Le contenu doit être persisté avant de programmer Instagram.');
        }

        $sourceType = $content instanceof HikeDraft
            ? InstagramPublicationSourceType::Hike
            : InstagramPublicationSourceType::CityVisit;
        $existing = $this->publicationRepository->findOneBySource($sourceType, $sourceId);
        if ($existing instanceof InstagramPublication) {
            return null;
        }

        $media = $this->mediaCollector->collect($content);
        $batches = $this->mediaBatcher->batch($media);
        $batchCount = count($batches);
        $publication = new InstagramPublication(
            $sourceType,
            $sourceId,
            $this->captionBuilder->build($content),
        );

        if ($batches === []) {
            $publication->setStatus(InstagramPublicationStatus::NoMedia);
            $this->entityManager->persist($publication);

            return $publication->synchronizeCounters();
        }

        $globalPosition = 1;
        foreach ($batches as $batchIndex => $batchMedia) {
            $batchCaption = $this->captionBuilder->build($content, $batchIndex + 1, $batchCount);
            $batch = new InstagramPublicationBatch(
                $publication,
                $batchIndex + 1,
                $batchCaption,
            );

            foreach ($batchMedia as $batchPosition => $instagramMedia) {
                new InstagramPublicationMedia(
                    $publication,
                    $batch,
                    $instagramMedia->mediaAsset,
                    $instagramMedia->mediaId,
                    $globalPosition++,
                    $batchPosition + 1,
                    $instagramMedia->url,
                    $batchCaption,
                );
            }

            $batch->synchronizeMediaCount();
        }

        $publication->synchronizeCounters();
        $this->entityManager->persist($publication);

        return $publication;
    }

    public function findForContent(HikeDraft|CityVisitDraft $content): ?InstagramPublication
    {
        $sourceId = $content->getId();
        if ($sourceId === null) {
            return null;
        }

        return $this->publicationRepository->findOneBySource(
            $content instanceof HikeDraft
                ? InstagramPublicationSourceType::Hike
                : InstagramPublicationSourceType::CityVisit,
            $sourceId,
        );
    }

    /**
     * Must be called after the transaction which created the publication has committed.
     * A failed dispatch deliberately leaves a durable pending outbox row for reconciliation.
     */
    public function dispatchAfterCommit(InstagramPublication $publication): bool
    {
        $publicationId = $publication->getId();
        if (
            $publicationId === null
            || $publication->getStatus() !== InstagramPublicationStatus::Pending
            || $publication->getTotalBatchCount() === 0
        ) {
            return false;
        }

        try {
            $this->messageBus->dispatch(PublishInstagramContent::forPublication($publicationId));
            $publication->markDispatched()->clearLastError();
            $this->entityManager->flush();

            $this->logger->info('Publication Instagram programmée.', $this->logContext($publication));

            return true;
        } catch (\Throwable $exception) {
            $publication
                ->setLastError('La tâche Instagram n’a pas pu être ajoutée à la file. Une nouvelle tentative automatique est prévue.')
                ->setLastErrorCode('dispatch_failed');

            try {
                $this->entityManager->flush();
            } catch (\Throwable) {
                // The Estela transaction is already committed; never turn this into an HTTP failure.
            }

            $this->logger->warning('Échec de programmation Instagram après commit.', [
                ...$this->logContext($publication),
                'error_code' => 'dispatch_failed',
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function retry(int $publicationId): bool
    {
        /** @var InstagramPublication|null $publication */
        $publication = $this->entityManager->wrapInTransaction(function () use ($publicationId): ?InstagramPublication {
            $publication = $this->publicationRepository->findOneForUpdate($publicationId);
            if (!$publication instanceof InstagramPublication || !$publication->canRetry()) {
                return null;
            }

            $publication
                ->setStatus(InstagramPublicationStatus::Pending)
                ->clearProcessingToken()
                ->clearLastError()
                ->setLastDispatchedAt(null);

            foreach ($publication->getBatches() as $batch) {
                if ($batch->getStatus() === InstagramPublicationBatchStatus::Published) {
                    continue;
                }

                $batch
                    ->setStatus(InstagramPublicationBatchStatus::Pending)
                    ->clearLastError();
                foreach ($batch->getMedia() as $media) {
                    $media->clearLastError();
                }
            }

            $this->entityManager->flush();

            return $publication;
        });

        return $publication instanceof InstagramPublication && $this->dispatchAfterCommit($publication);
    }

    public function recoverAndDispatch(
        int $publicationId,
        DateTimeImmutable $pendingCutoff,
        DateTimeImmutable $processingCutoff,
    ): bool {
        /** @var InstagramPublication|null $publication */
        $publication = $this->entityManager->wrapInTransaction(function () use ($publicationId, $pendingCutoff, $processingCutoff): ?InstagramPublication {
            $publication = $this->publicationRepository->findOneForUpdate($publicationId);
            if (!$publication instanceof InstagramPublication || $publication->isTerminal()) {
                return null;
            }

            if ($publication->getStatus() === InstagramPublicationStatus::Processing) {
                $lastAttemptAt = $publication->getLastAttemptAt();
                if ($lastAttemptAt !== null && $lastAttemptAt > $processingCutoff) {
                    return null;
                }

                $publication
                    ->setStatus(InstagramPublicationStatus::Pending)
                    ->clearProcessingToken()
                    ->setLastDispatchedAt(null);

                foreach ($publication->getBatches() as $batch) {
                    if ($batch->getStatus() === InstagramPublicationBatchStatus::Processing) {
                        $batch->setStatus(InstagramPublicationBatchStatus::Pending);
                    }
                }
            }

            if ($publication->getStatus() !== InstagramPublicationStatus::Pending) {
                return null;
            }

            $lastDispatchedAt = $publication->getLastDispatchedAt();
            if ($lastDispatchedAt !== null && $lastDispatchedAt > $pendingCutoff) {
                return null;
            }

            $this->entityManager->flush();

            return $publication;
        });

        return $publication instanceof InstagramPublication && $this->dispatchAfterCommit($publication);
    }

    /** @return array<string, int|string> */
    private function logContext(InstagramPublication $publication): array
    {
        return [
            'instagram_publication_id' => $publication->getId() ?? 0,
            'source_type' => $publication->getSourceType()->value,
            'source_id' => $publication->getSourceId(),
            'batch_count' => $publication->getTotalBatchCount(),
            'media_count' => $publication->getTotalMediaCount(),
            'status' => $publication->getStatus()->value,
        ];
    }
}
