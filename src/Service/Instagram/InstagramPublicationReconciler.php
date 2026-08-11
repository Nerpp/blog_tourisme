<?php

namespace App\Service\Instagram;

use App\Repository\InstagramPublicationRepository;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;

final readonly class InstagramPublicationReconciler
{
    public const int DEFAULT_LIMIT = 50;
    public const int DEFAULT_PENDING_AGE_SECONDS = 300;
    public const int DEFAULT_PROCESSING_AGE_SECONDS = 1800;
    public const int MAX_LIMIT = 500;
    public const string LOCK_RESOURCE = 'estela.instagram.reconcile';
    public const float LOCK_TTL_SECONDS = 720.0;

    public function __construct(
        private InstagramPublicationRepository $publicationRepository,
        private InstagramPublicationScheduler $publicationScheduler,
        #[Target('instagram_webcron')]
        private LockFactory $instagramWebcronLockFactory,
    ) {
    }

    public function reconcile(
        DateTimeImmutable $now,
        int $pendingAgeSeconds = self::DEFAULT_PENDING_AGE_SECONDS,
        int $processingAgeSeconds = self::DEFAULT_PROCESSING_AGE_SECONDS,
        int $limit = self::DEFAULT_LIMIT,
    ): InstagramPublicationReconciliationResult {
        if ($pendingAgeSeconds < 1 || $processingAgeSeconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Les délais et la limite de réconciliation Instagram doivent être strictement positifs.');
        }

        $lock = $this->instagramWebcronLockFactory->createLock(
            self::LOCK_RESOURCE,
            self::LOCK_TTL_SECONDS,
        );
        if (!$lock->acquire(blocking: false)) {
            return new InstagramPublicationReconciliationResult(0, 0, skippedDueToLock: true);
        }

        try {
            $pendingCutoff = $now->modify(sprintf('-%d seconds', $pendingAgeSeconds));
            $processingCutoff = $now->modify(sprintf('-%d seconds', $processingAgeSeconds));
            $publications = $this->publicationRepository->findRecoverableForDispatch(
                $pendingCutoff,
                $processingCutoff,
                min($limit, self::MAX_LIMIT),
            );

            $dispatchedCount = 0;
            $seenPublicationIds = [];
            foreach ($publications as $publication) {
                $publicationId = $publication->getId();
                if ($publicationId === null || isset($seenPublicationIds[$publicationId])) {
                    continue;
                }

                $seenPublicationIds[$publicationId] = true;
                $lock->refresh(self::LOCK_TTL_SECONDS);
                if ($this->publicationScheduler->recoverAndDispatch(
                    $publicationId,
                    $pendingCutoff,
                    $processingCutoff,
                )) {
                    ++$dispatchedCount;
                }
            }

            return new InstagramPublicationReconciliationResult(
                count($publications),
                $dispatchedCount,
            );
        } finally {
            $lock->release();
        }
    }
}
