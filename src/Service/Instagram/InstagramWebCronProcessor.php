<?php

namespace App\Service\Instagram;

use App\Service\Facebook\FacebookPublicationReconciler;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Coordinates both publication recoveries before draining the bounded social receivers. */
final readonly class InstagramWebCronProcessor implements InstagramWebCronProcessorInterface
{
    public function __construct(
        private InstagramPublicationReconciler $publicationReconciler,
        private FacebookPublicationReconciler $facebookPublicationReconciler,
        private InstagramMessageConsumer $messageConsumer,
        private InstagramExecutionBudget $executionBudget,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $messageLimit,
        private int $timeLimitSeconds,
    ) {
        if ($this->messageLimit < 1 || $this->messageLimit > InstagramMessageConsumer::MAX_MESSAGE_LIMIT) {
            throw new \InvalidArgumentException('The Instagram WebCron message limit is outside the safe range.');
        }

        if ($this->timeLimitSeconds < 1 || $this->timeLimitSeconds > InstagramMessageConsumer::MAX_TIME_LIMIT_SECONDS) {
            throw new \InvalidArgumentException('The Instagram WebCron time limit is outside the safe range.');
        }
    }

    public function run(): void
    {
        $startedAt = $this->timestamp();
        $this->executionBudget->activate($this->timeLimitSeconds);
        try {
            $reconciliation = $this->publicationReconciler->reconcile(
                DateTimeImmutable::createFromInterface($this->clock->now()),
            );
            $facebookReconciliation = $this->facebookPublicationReconciler->reconcile(
                DateTimeImmutable::createFromInterface($this->clock->now()),
            );
            $consumption = $this->messageConsumer->consume(
                $this->messageLimit,
                $this->timeLimitSeconds,
            );
        } finally {
            $this->executionBudget->deactivate();
        }

        $this->logger->info('Cycle WebCron Instagram terminé.', [
            'reconciliation_candidate_count' => $reconciliation->candidateCount,
            'reconciliation_dispatched_count' => $reconciliation->dispatchedCount,
            'reconciliation_skipped_due_to_lock' => $reconciliation->skippedDueToLock,
            'facebook_reconciliation_candidate_count' => $facebookReconciliation->candidateCount,
            'facebook_reconciliation_dispatched_count' => $facebookReconciliation->dispatchedCount,
            'handled_count' => $consumption->handled,
            'failed_count' => $consumption->failed,
            'retried_count' => $consumption->retried,
            'terminal_failed_count' => $consumption->terminalFailed(),
            'elapsed_milliseconds' => $consumption->elapsedMilliseconds,
            'cycle_elapsed_milliseconds' => max(0, (int) round(($this->timestamp() - $startedAt) * 1000)),
        ]);
    }

    private function timestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
