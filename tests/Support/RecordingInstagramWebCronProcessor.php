<?php

namespace App\Tests\Support;

use App\Service\Instagram\InstagramWebCronProcessorInterface;

final class RecordingInstagramWebCronProcessor implements InstagramWebCronProcessorInterface
{
    public int $callCount = 0;

    public ?\Throwable $exception = null;

    public function run(): void
    {
        ++$this->callCount;

        if ($this->exception !== null) {
            throw $this->exception;
        }
    }
}
