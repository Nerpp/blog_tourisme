<?php

namespace App\Service\Instagram;

final readonly class InstagramPublicationReconciliationResult
{
    public function __construct(
        public int $candidateCount,
        public int $dispatchedCount,
        public bool $skippedDueToLock = false,
    ) {
    }
}
