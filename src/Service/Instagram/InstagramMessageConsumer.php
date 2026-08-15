<?php

namespace App\Service\Instagram;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Runs the facebook_async and instagram_async receivers in-process for a short
 * HTTP/WebCron window. The historical class name is kept for production
 * compatibility with the existing WebCron architecture.
 *
 * The time limit is cooperative: Messenger evaluates it between envelopes and
 * the Instagram handler checks the shared reserve before every remote call.
 * A remote call already in progress is intentionally not preempted.
 */
final readonly class InstagramMessageConsumer
{
    public const int DEFAULT_MESSAGE_LIMIT = 5;
    public const int DEFAULT_TIME_LIMIT_SECONDS = 240;
    public const int MAX_MESSAGE_LIMIT = 20;
    public const int MAX_TIME_LIMIT_SECONDS = 240;

    private const string FACEBOOK_RECEIVER_NAME = 'facebook_async';
    private const string INSTAGRAM_RECEIVER_NAME = 'instagram_async';

    public function __construct(
        private ReceiverInterface $instagramReceiver,
        private ReceiverInterface $facebookReceiver,
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

        $received = 0;
        $handled = 0;
        $failed = 0;
        $retried = 0;
        $startedAt = $this->timestamp();
        $localDeadline = $startedAt + $timeLimitSeconds;

        $ownsExecutionBudget = !$this->executionBudget->isActive();
        if ($ownsExecutionBudget) {
            $this->executionBudget->activate($timeLimitSeconds);
        }

        $effectiveTimeLimitSeconds = $this->effectiveTimeLimitSeconds($localDeadline);
        if ($effectiveTimeLimitSeconds < 1 || $this->executionBudget->shouldYieldBeforeNextBatch()) {
            if ($ownsExecutionBudget) {
                $this->executionBudget->deactivate();
            }

            $this->logger->info('Consommation HTTP sociale ignorée, budget insuffisant.', [
                'transports' => [self::INSTAGRAM_RECEIVER_NAME, self::FACEBOOK_RECEIVER_NAME],
                'remaining_seconds' => max(0, $effectiveTimeLimitSeconds),
                'batch_reserve_seconds' => $this->executionBudget->minimumRemainingSeconds(),
            ]);

            return new InstagramConsumeResult(0, 0, 0, $this->elapsedMilliseconds($startedAt));
        }

        $countReceived = static function (WorkerMessageReceivedEvent $event) use (&$received): void {
            if (self::isSocialReceiver($event->getReceiverName())) {
                ++$received;
            }
        };
        $countHandled = static function (WorkerMessageHandledEvent $event) use (&$handled): void {
            if (self::isSocialReceiver($event->getReceiverName())) {
                ++$handled;
            }
        };
        $countFailed = static function (WorkerMessageFailedEvent $event) use (&$failed): void {
            if (self::isSocialReceiver($event->getReceiverName())) {
                ++$failed;
            }
        };
        $countRetried = static function (WorkerMessageRetriedEvent $event) use (&$retried): void {
            if (self::isSocialReceiver($event->getReceiverName())) {
                ++$retried;
            }
        };

        try {
            $this->eventDispatcher->addListener(WorkerMessageReceivedEvent::class, $countReceived);
            $this->eventDispatcher->addListener(WorkerMessageHandledEvent::class, $countHandled);
            // The retry listener has priority 100. Counting at -50 observes its
            // decision while still preceding the failure transport listener (-100).
            $this->eventDispatcher->addListener(WorkerMessageFailedEvent::class, $countFailed, -50);
            $this->eventDispatcher->addListener(WorkerMessageRetriedEvent::class, $countRetried);

            $this->logger->info('Consommation HTTP sociale démarrée.', [
                'transports' => [self::INSTAGRAM_RECEIVER_NAME, self::FACEBOOK_RECEIVER_NAME],
                'message_limit' => $messageLimit,
                'time_limit_seconds' => $effectiveTimeLimitSeconds,
                'requested_time_limit_seconds' => $timeLimitSeconds,
                'batch_reserve_seconds' => $this->executionBudget->minimumRemainingSeconds(),
            ]);

            // A fixed receiver order can starve the second queue in Symfony's Worker.
            // Reserve the first envelope for Facebook, then give Instagram priority
            // for the globally bounded remainder; either phase falls back to the
            // other receiver when its preferred queue is empty.
            $this->runPhase(
                [
                    self::FACEBOOK_RECEIVER_NAME => $this->facebookReceiver,
                    self::INSTAGRAM_RECEIVER_NAME => $this->instagramReceiver,
                ],
                1,
                $localDeadline,
            );

            $remainingMessageLimit = $messageLimit - $received;
            if ($remainingMessageLimit > 0) {
                $this->runPhase(
                    [
                        self::INSTAGRAM_RECEIVER_NAME => $this->instagramReceiver,
                        self::FACEBOOK_RECEIVER_NAME => $this->facebookReceiver,
                    ],
                    $remainingMessageLimit,
                    $localDeadline,
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('La consommation HTTP sociale a été interrompue.', [
                'transports' => [self::INSTAGRAM_RECEIVER_NAME, self::FACEBOOK_RECEIVER_NAME],
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        } finally {
            $this->eventDispatcher->removeListener(WorkerMessageReceivedEvent::class, $countReceived);
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

        $this->logger->info('Consommation HTTP sociale terminée.', [
            'transports' => [self::INSTAGRAM_RECEIVER_NAME, self::FACEBOOK_RECEIVER_NAME],
            'handled_count' => $result->handled,
            'failed_count' => $result->failed,
            'retried_count' => $result->retried,
            'terminal_failed_count' => $result->terminalFailed(),
            'elapsed_milliseconds' => $result->elapsedMilliseconds,
        ]);

        return $result;
    }

    /** @param array<string, ReceiverInterface> $receivers */
    private function runPhase(array $receivers, int $messageLimit, float $localDeadline): void
    {
        $effectiveTimeLimitSeconds = $this->effectiveTimeLimitSeconds($localDeadline);
        if (
            $messageLimit < 1
            || $effectiveTimeLimitSeconds < 1
            || $this->executionBudget->shouldYieldBeforeNextBatch()
        ) {
            return;
        }

        $worker = new Worker(
            $receivers,
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

        try {
            $this->eventDispatcher->addSubscriber($messageLimitListener);
            $this->eventDispatcher->addListener(WorkerRunningEvent::class, $stopOnIdleOrLowBudget, 200);
            $worker->run([
                'sleep' => 0,
                'time_limit' => $effectiveTimeLimitSeconds,
                'fetch_size' => 1,
            ]);
        } finally {
            $this->eventDispatcher->removeSubscriber($messageLimitListener);
            $this->eventDispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdleOrLowBudget);
        }
    }

    private static function isSocialReceiver(string $receiverName): bool
    {
        return self::INSTAGRAM_RECEIVER_NAME === $receiverName
            || self::FACEBOOK_RECEIVER_NAME === $receiverName;
    }

    private function effectiveTimeLimitSeconds(float $localDeadline): int
    {
        $executionBudgetRemaining = $this->executionBudget->remainingSeconds() ?? 0.0;
        $localRemaining = $localDeadline - $this->timestamp();

        return (int) floor(max(0.0, min($executionBudgetRemaining, $localRemaining)));
    }

    private function validateLimits(int $messageLimit, int $timeLimitSeconds): void
    {
        if ($messageLimit < 1 || $messageLimit > self::MAX_MESSAGE_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'The HTTP consumer message limit must be between 1 and %d.',
                self::MAX_MESSAGE_LIMIT,
            ));
        }

        if ($timeLimitSeconds < 1 || $timeLimitSeconds > self::MAX_TIME_LIMIT_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'The HTTP consumer time limit must be between 1 and %d seconds.',
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
