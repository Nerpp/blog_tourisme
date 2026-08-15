<?php

namespace App\Service\Facebook;

final readonly class FacebookPublicationReconciliationResult
{
    public function __construct(
        public int $candidateCount,
        public int $dispatchedCount,
    ) {
    }
}
