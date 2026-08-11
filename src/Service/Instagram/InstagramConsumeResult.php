<?php

namespace App\Service\Instagram;

final readonly class InstagramConsumeResult
{
    public function __construct(
        public int $handled,
        public int $failed,
        public int $retried,
        public int $elapsedMilliseconds,
    ) {
        if ($this->handled < 0 || $this->failed < 0 || $this->retried < 0 || $this->elapsedMilliseconds < 0) {
            throw new \InvalidArgumentException('Instagram consumer counters cannot be negative.');
        }

        if ($this->retried > $this->failed) {
            throw new \InvalidArgumentException('Instagram retried attempts cannot exceed failed attempts.');
        }
    }

    public function processed(): int
    {
        return $this->handled + $this->failed;
    }

    public function terminalFailed(): int
    {
        return $this->failed - $this->retried;
    }
}
