<?php

namespace App\MessageHandler;

use App\Entity\FacebookPublication;
use App\Message\PublishFacebookContent;
use App\Repository\FacebookPublicationRepository;
use App\Service\Facebook\FacebookPublisher;
use App\Service\Facebook\FacebookPublisherException;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @phpstan-type FacebookClaim array{
 *     publicationId: int,
 *     sourceType: string,
 *     sourceId: int,
 *     message: string,
 *     link: string,
 *     attemptCount: int
 * }
 */
#[AsMessageHandler]
final class PublishFacebookContentHandler
{
    private const PROCESSING_LEASE_MINUTES = 30;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FacebookPublicationRepository $publicationRepository,
        private readonly FacebookPublisher $publisher,
        private readonly LoggerInterface $logger,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function __invoke(PublishFacebookContent $message): void
    {
        $claim = $this->claimPublication($message);
        if (null === $claim) {
            return;
        }

        try {
            $facebookPostId = $this->publisher->publishLink($claim['message'], $claim['link']);
        } catch (FacebookPublisherException $exception) {
            $errorCode = $this->failureCode($exception);
            if ($this->recordFailure(
                $claim['publicationId'],
                $message->executionId,
                $exception->getMessage(),
                $errorCode,
            )) {
                $this->logger->error('Échec de publication Facebook.', [
                    ...$this->logContext($claim),
                    'status' => 'failed',
                    'error_code' => $errorCode,
                    'http_status' => $exception->httpStatus,
                    'meta_error_code' => $exception->metaErrorCode,
                ]);
            } else {
                $this->logOwnershipLost($claim);
            }

            // A normal exception lets the finite retry strategy of facebook_async decide.
            throw $exception;
        }

        if (!$this->recordSuccess(
            $claim['publicationId'],
            $message->executionId,
            $facebookPostId,
        )) {
            $this->logOwnershipLost($claim);

            return;
        }

        $this->logger->info('Publication Facebook publiée.', [
            ...$this->logContext($claim),
            'status' => 'published',
        ]);
    }

    /** @return FacebookClaim|null */
    private function claimPublication(PublishFacebookContent $message): ?array
    {
        return $this->entityManager->wrapInTransaction(function () use ($message): ?array {
            $publication = $this->publicationRepository->findOneForUpdate($message->publicationId);
            if (!$publication instanceof FacebookPublication) {
                $this->logger->warning('Publication Facebook introuvable, message ignoré.', [
                    'facebook_publication_id' => $message->publicationId,
                ]);

                return null;
            }

            if ($publication->isTerminal()) {
                $this->logger->info('Publication Facebook terminale, message ignoré.', [
                    'facebook_publication_id' => $message->publicationId,
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
                $this->logger->info('Publication Facebook déjà revendiquée, message concurrent ignoré.', [
                    'facebook_publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                    'status' => $publication->getStatus()->value,
                ]);

                return null;
            }

            if (null !== $processingToken && !$sameExecution) {
                $this->logger->warning('Reprise d’une publication Facebook dont le verrou applicatif a expiré.', [
                    'facebook_publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                ]);
            } elseif ($sameExecution) {
                $this->logger->info('Reprise d’une exécution Facebook après redelivery Messenger.', [
                    'facebook_publication_id' => $message->publicationId,
                    'source_type' => $publication->getSourceType()->value,
                    'source_id' => $publication->getSourceId(),
                ]);
            }

            $publication->markProcessing($message->executionId, $now);

            $publicationId = $publication->getId();
            if (null === $publicationId) {
                throw new LogicException('Une publication Facebook persistée doit posséder un identifiant.');
            }

            return [
                'publicationId' => $publicationId,
                'sourceType' => $publication->getSourceType()->value,
                'sourceId' => $publication->getSourceId(),
                'message' => $publication->getMessage(),
                'link' => $publication->getLink(),
                'attemptCount' => $publication->getAttemptCount(),
            ];
        });
    }

    private function recordSuccess(
        int $publicationId,
        string $executionId,
        string $facebookPostId,
    ): bool {
        return $this->entityManager->wrapInTransaction(function () use (
            $publicationId,
            $executionId,
            $facebookPostId,
        ): bool {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return false;
            }

            $publication->markPublished($facebookPostId, $this->currentTime());

            return true;
        });
    }

    private function recordFailure(
        int $publicationId,
        string $executionId,
        string $error,
        string $errorCode,
    ): bool {
        return $this->entityManager->wrapInTransaction(function () use (
            $publicationId,
            $executionId,
            $error,
            $errorCode,
        ): bool {
            $publication = $this->lockedOwnedPublication($publicationId, $executionId);
            if (null === $publication) {
                return false;
            }

            $publication->markFailed($error, $errorCode, $this->currentTime());

            return true;
        });
    }

    private function lockedOwnedPublication(int $publicationId, string $executionId): ?FacebookPublication
    {
        $publication = $this->publicationRepository->findOneForUpdate($publicationId);

        return $publication instanceof FacebookPublication
            && $publication->getProcessingToken() === $executionId
                ? $publication
                : null;
    }

    private function hasStaleLease(FacebookPublication $publication, DateTimeImmutable $now): bool
    {
        $lastAttemptAt = $publication->getLastAttemptAt();
        if (null === $lastAttemptAt) {
            return true;
        }

        $cutoff = $now->sub(new DateInterval(sprintf('PT%dM', self::PROCESSING_LEASE_MINUTES)));

        return $lastAttemptAt < $cutoff;
    }

    private function currentTime(): DateTimeImmutable
    {
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new LogicException('L’horloge Facebook doit retourner une date immuable.');
        }

        return $now;
    }

    private function failureCode(FacebookPublisherException $exception): string
    {
        if (null === $exception->httpStatus) {
            return 'facebook_publisher_error';
        }

        $code = 'http_'.$exception->httpStatus;
        if (null !== $exception->metaErrorCode) {
            $code .= ':meta_'.$exception->metaErrorCode;
        }

        return $code;
    }

    /**
     * @param FacebookClaim $claim
     *
     * @return array{facebook_publication_id: int, source_type: string, source_id: int, attempt_count: int}
     */
    private function logContext(array $claim): array
    {
        return [
            'facebook_publication_id' => $claim['publicationId'],
            'source_type' => $claim['sourceType'],
            'source_id' => $claim['sourceId'],
            'attempt_count' => $claim['attemptCount'],
        ];
    }

    /** @param FacebookClaim $claim */
    private function logOwnershipLost(array $claim): void
    {
        $this->logger->info('Traitement Facebook interrompu après perte du verrou applicatif.', [
            ...$this->logContext($claim),
            'status' => 'ownership_lost',
        ]);
    }
}
