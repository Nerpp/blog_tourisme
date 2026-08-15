<?php

namespace App\Service\Facebook;

use App\Repository\FacebookPublicationRepository;
use DateTimeImmutable;

final readonly class FacebookPublicationReconciler
{
    public const int DEFAULT_LIMIT = 50;
    public const int DEFAULT_PENDING_AGE_SECONDS = 300;
    public const int MAX_LIMIT = 500;

    public function __construct(
        private FacebookPublicationRepository $publicationRepository,
        private FacebookPublicationScheduler $publicationScheduler,
    ) {
    }

    public function reconcile(
        DateTimeImmutable $now,
        int $pendingAgeSeconds = self::DEFAULT_PENDING_AGE_SECONDS,
        int $limit = self::DEFAULT_LIMIT,
    ): FacebookPublicationReconciliationResult {
        if ($pendingAgeSeconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Le délai et la limite de réconciliation Facebook doivent être strictement positifs.');
        }

        $pendingCutoff = $now->modify(sprintf('-%d seconds', $pendingAgeSeconds));
        $publications = $this->publicationRepository->findPendingForDispatch(
            $pendingCutoff,
            min($limit, self::MAX_LIMIT),
        );

        $dispatchedCount = 0;
        $seenPublicationIds = [];
        foreach ($publications as $publication) {
            $publicationId = $publication->getId();
            if (null === $publicationId || isset($seenPublicationIds[$publicationId])) {
                continue;
            }

            $seenPublicationIds[$publicationId] = true;
            if ($this->publicationScheduler->recoverAndDispatch($publicationId, $pendingCutoff)) {
                ++$dispatchedCount;
            }
        }

        return new FacebookPublicationReconciliationResult(
            count($publications),
            $dispatchedCount,
        );
    }
}
