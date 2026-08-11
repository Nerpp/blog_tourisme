<?php

namespace App\Service\Instagram;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Runs the instagram_async receiver in-process for a short HTTP/WebCron window.
 *
 * The time limit is cooperative: Messenger evaluates it between envelopes and
 * the publication handler checks the shared reserve before every remote call.
 * A remote call already in progress is intentionally not preempted.
 */
final readonly class InstagramMessageConsumer
{
    public const int DEFAULT_MESSAGE_LIMIT = 5;
    public const int DEFAULT_TIME_LIMIT_SECONDS = 240;
    public const int MAX_MESSAGE_LIMIT = 20;
    public const int MAX_TIME_LIMIT_SECONDS = 240;

    private const string RECEIVER_NAME = 'instagram_async';

    public function __construct(
        private ReceiverInterface $instagramReceiver,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private InstagramExecutionBudget $executionBudget,
        private LoggerInterface $logger,
    ) {
    }

    public function consume(
        int $messageLimit = self::DEFAULT_MESSAGE_LIMIT,
        int $timeLimitSeconds = self::DEFAULT_TIME_LIMIT_SECONDS,
    ): InstagramConsumeResult {
        $this->validateLimits($messageLimit, $timeLimitSeconds);

        $handled = 0;
        $failed = 0;
        $retried = 0;
        $startedAt = $this->timestamp();

        $ownsExecutionBudget = !$this->executionBudget->isActive();
        if ($ownsExecutionBudget) {
            $this->executionBudget->activate($timeLimitSeconds);
        }

        $remainingSeconds = $this->executionBudget->remainingSeconds() ?? 0.0;
        $effectiveTimeLimitSeconds = min($timeLimitSeconds, (int) floor($remainingSeconds));
        if ($effectiveTimeLimitSeconds < 1 || $this->executionBudget->shouldYieldBeforeNextBatch()) {
            if ($ownsExecutionBudget) {
                $this->executionBudget->deactivate();
            }

            $this->logger->info('Consommation HTTP Instagram ignorée, budget insuffisant.', [
                'transport' => self::RECEIVER_NAME,
                'remaining_seconds' => max(0, $effectiveTimeLimitSeconds),
                'batch_reserve_seconds' => $this->executionBudget->minimumRemainingSeconds(),
            ]);

            return new InstagramConsumeResult(0, 0, 0, $this->elapsedMilliseconds($startedAt));
        }

        $worker = new Worker(
            [self::RECEIVER_NAME => $this->instagramReceiver],
            $this->bus,
            $this->eventDispatcher,
            $this->logger,
            clock: $this->clock,
        );
        $messageLimitListener = new StopWorkerOnMessageLimitListener($messageLimit, $this->logger);

        $stopOnIdleOrLowBudget = function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle() || $this->executionBudget->shouldYieldBeforeNextBatch()) {
                $event->getWorker()->stop();
            }
        };
        $countHandled = static function (WorkerMessageHandledEvent $event) use (&$handled): void {
            if (self::RECEIVER_NAME === $event->getReceiverName()) {
                ++$handled;
            }
        };
        $countFailed = static function (WorkerMessageFailedEvent $event) use (&$failed): void {
            if (self::RECEIVER_NAME === $event->getReceiverName()) {
                ++$failed;
            }
        };
        $countRetried = static function (WorkerMessageRetriedEvent $event) use (&$retried): void {
            if (self::RECEIVER_NAME === $event->getReceiverName()) {
                ++$retried;
            }
        };

        try {
            $this->eventDispatcher->addSubscriber($messageLimitListener);
            $this->eventDispatcher->addListener(WorkerRunningEvent::class, $stopOnIdleOrLowBudget, 200);
            $this->eventDispatcher->addListener(WorkerMessageHandledEvent::class, $countHandled);
            // The retry listener has priority 100. Counting at -50 observes its
            // decision while still preceding the failure transport listener (-100).
            $this->eventDispatcher->addListener(WorkerMessageFailedEvent::class, $countFailed, -50);
            $this->eventDispatcher->addListener(WorkerMessageRetriedEvent::class, $countRetried);

            $this->logger->info('Consommation HTTP Instagram démarrée.', [
                'transport' => self::RECEIVER_NAME,
                'message_limit' => $messageLimit,
                'time_limit_seconds' => $effectiveTimeLimitSeconds,
                'requested_time_limit_seconds' => $timeLimitSeconds,
                'batch_reserve_seconds' => $this->executionBudget->minimumRemainingSeconds(),
            ]);

            $worker->run([
                'sleep' => 0,
                'time_limit' => $effectiveTimeLimitSeconds,
                'fetch_size' => 1,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('La consommation HTTP Instagram a été interrompue.', [
                'transport' => self::RECEIVER_NAME,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        } finally {
            $this->eventDispatcher->removeSubscriber($messageLimitListener);
            $this->eventDispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdleOrLowBudget);
            $this->eventDispatcher->removeListener(WorkerMessageHandledEvent::class, $countHandled);
            $this->eventDispatcher->removeListener(WorkerMessageFailedEvent::class, $countFailed);
            $this->eventDispatcher->removeListener(WorkerMessageRetriedEvent::class, $countRetried);
            if ($ownsExecutionBudget) {
                $this->executionBudget->deactivate();
            }
        }

        $result = new InstagramConsumeResult(
            $handled,
            $failed,
            $retried,
            $this->elapsedMilliseconds($startedAt),
        );

        $this->logger->info('Consommation HTTP Instagram terminée.', [
            'transport' => self::RECEIVER_NAME,
            'handled_count' => $result->handled,
            'failed_count' => $result->failed,
            'retried_count' => $result->retried,
            'terminal_failed_count' => $result->terminalFailed(),
            'elapsed_milliseconds' => $result->elapsedMilliseconds,
        ]);

        return $result;
    }

    private function validateLimits(int $messageLimit, int $timeLimitSeconds): void
    {
        if ($messageLimit < 1 || $messageLimit > self::MAX_MESSAGE_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'The Instagram HTTP consumer message limit must be between 1 and %d.',
                self::MAX_MESSAGE_LIMIT,
            ));
        }

        if ($timeLimitSeconds < 1 || $timeLimitSeconds > self::MAX_TIME_LIMIT_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'The Instagram HTTP consumer time limit must be between 1 and %d seconds.',
                self::MAX_TIME_LIMIT_SECONDS,
            ));
        }
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round(($this->timestamp() - $startedAt) * 1000));
    }

    private function timestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
