<?php

namespace App\Service\Instagram;

use LogicException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Request-local cooperative deadline shared by the bounded HTTP consumer and
 * the Instagram handler. It remains inactive for regular CLI Messenger workers.
 */
final class InstagramExecutionBudget
{
    private ?float $deadline = null;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $minimumRemainingSeconds,
    ) {
        if ($this->minimumRemainingSeconds < 1) {
            throw new \InvalidArgumentException('The Instagram batch time reserve must be positive.');
        }
    }

    public function activate(int $timeLimitSeconds): void
    {
        if ($timeLimitSeconds < 1) {
            throw new \InvalidArgumentException('The Instagram execution time limit must be positive.');
        }

        if (null !== $this->deadline) {
            throw new LogicException('The Instagram execution budget is already active.');
        }

        $this->deadline = $this->timestamp() + $timeLimitSeconds;
    }

    public function deactivate(): void
    {
        $this->deadline = null;
    }

    public function isActive(): bool
    {
        return null !== $this->deadline;
    }

    /**
     * This is deliberately checked only at safe cooperative boundaries. A Meta
     * request that has already started is never interrupted midway.
     */
    public function shouldYieldBeforeNextBatch(): bool
    {
        return $this->shouldYieldBeforeRemoteOperation($this->minimumRemainingSeconds);
    }

    public function shouldYieldBeforeRemoteOperation(int $requiredSeconds): bool
    {
        if ($requiredSeconds < 1) {
            throw new \InvalidArgumentException('The Instagram operation time reserve must be positive.');
        }

        return null !== $this->deadline
            && $this->deadline - $this->timestamp() < $requiredSeconds;
    }

    public function remainingSeconds(): ?float
    {
        if (null === $this->deadline) {
            return null;
        }

        return max(0.0, $this->deadline - $this->timestamp());
    }

    public function minimumRemainingSeconds(): int
    {
        return $this->minimumRemainingSeconds;
    }

    private function timestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
