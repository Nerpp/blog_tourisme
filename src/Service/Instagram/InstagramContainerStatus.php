<?php

namespace App\Service\Instagram;

enum InstagramContainerStatus: string
{
    case InProgress = 'IN_PROGRESS';
    case Finished = 'FINISHED';
    case Error = 'ERROR';
    case Expired = 'EXPIRED';
    case Published = 'PUBLISHED';

    public function isReady(): bool
    {
        return self::Finished === $this || self::Published === $this;
    }

    public function isFailure(): bool
    {
        return self::Error === $this || self::Expired === $this;
    }
}
