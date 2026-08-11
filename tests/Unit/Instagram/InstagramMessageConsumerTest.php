<?php

namespace App\Tests\Unit\Instagram;

use App\Service\Instagram\InstagramExecutionBudget;
use App\Service\Instagram\InstagramMessageConsumer;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class InstagramMessageConsumerTest extends TestCase
{
    public function testItStopsImmediatelyOnAnEmptyQueueAndRemovesEveryTemporaryListener(): void
    {
        $clock = new MockClock('2026-08-11T12:00:00+02:00');
        $transport = new InMemoryTransport(clock: $clock);
        $dispatcher = new EventDispatcher();
        $budget = new InstagramExecutionBudget($clock, 120);
        $consumer = $this->consumer(
            $transport,
            new CallbackMessageBus(static fn (Envelope $envelope): Envelope => $envelope),
            $dispatcher,
            $clock,
            $budget,
        );

        $result = $consumer->consume();

        self::assertSame(0, $result->handled);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $result->retried);
        self::assertSame(0, $result->processed());
        self::assertSame(0, $result->terminalFailed());
        self::assertSame(0, $result->elapsedMilliseconds);
        self::assertFalse($budget->isActive());
        self::assertSame([], $dispatcher->getListeners(WorkerRunningEvent::class));
        self::assertSame([], $dispatcher->getListeners(WorkerMessageHandledEvent::class));
        self::assertSame([], $dispatcher->getListeners(WorkerMessageFailedEvent::class));
        self::assertSame([], $dispatcher->getListeners(WorkerMessageRetriedEvent::class));
    }

    public function testItConsumesAtMostTheRequestedNumberOfMessagesWithFetchSizeOne(): void
    {
        $clock = new MockClock('2026-08-11T12:00:00+02:00');
        $transport = new InMemoryTransport(clock: $clock);
        for ($id = 1; $id <= 3; ++$id) {
            $transport->send(new Envelope(new ConsumerProbeMessage($id)));
        }

        $dispatcher = new EventDispatcher();
        $budget = new InstagramExecutionBudget($clock, 120);
        $consumer = $this->consumer(
            $transport,
            new CallbackMessageBus(static function (Envelope $envelope) use ($clock): Envelope {
                $clock->sleep(1);

                return $envelope;
            }),
            $dispatcher,
            $clock,
            $budget,
        );

        $first = $consumer->consume(2, 150);

        self::assertSame(2, $first->handled);
        self::assertSame(2, $first->processed());
        self::assertSame(2000, $first->elapsedMilliseconds);
        self::assertCount(2, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());

        $second = $consumer->consume(2, 150);
        self::assertSame(1, $second->handled);
        self::assertCount(3, $transport->getAcknowledged());
    }

    public function testTimeLimitIsASoftBoundaryBetweenMessagesAndDoesNotPreemptCurrentHandler(): void
    {
        $clock = new MockClock('2026-08-11T12:00:00+02:00');
        $transport = new InMemoryTransport(clock: $clock);
        $transport->send(new Envelope(new ConsumerProbeMessage(1)));
        $dispatcher = new EventDispatcher();
        $consumer = $this->consumer(
            $transport,
            new CallbackMessageBus(static function (Envelope $envelope) use ($clock): Envelope {
                $clock->sleep(10);

                return $envelope;
            }),
            $dispatcher,
            $clock,
            new InstagramExecutionBudget($clock, 1),
        );

        $result = $consumer->consume(5, 2);

        self::assertSame(1, $result->handled);
        self::assertSame(10_000, $result->elapsedMilliseconds);
        self::assertCount(1, $transport->getAcknowledged());
    }

    public function testItUsesMessengerRetryThenFailureTransportAndCountsActualRetryEvents(): void
    {
        $clock = new MockClock('2026-08-11T12:00:00+02:00');
        $instagramTransport = new InMemoryTransport(clock: $clock);
        $failedTransport = new InMemoryTransport(clock: $clock);
        $instagramTransport->send(new Envelope(new ConsumerProbeMessage(1)));
        $dispatcher = new EventDispatcher();
        $retryStrategy = new MultiplierRetryStrategy(
            maxRetries: 1,
            delayMilliseconds: 0,
            multiplier: 1,
            maxDelayMilliseconds: 0,
            jitter: 0,
        );
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator([
                'instagram_async' => static fn (): InMemoryTransport => $instagramTransport,
            ]),
            new ServiceLocator([
                'instagram_async' => static fn (): MultiplierRetryStrategy => $retryStrategy,
            ]),
            eventDispatcher: $dispatcher,
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator([
                'instagram_async' => static fn (): InMemoryTransport => $failedTransport,
            ]),
            failureTransportsByName: ['instagram_async' => 'failed'],
        ));
        $baselineListenerCounts = $this->listenerCounts($dispatcher);
        $consumer = $this->consumer(
            $instagramTransport,
            new CallbackMessageBus(static function (): never {
                throw new RecoverableMessageHandlingException('Safe retry test.', forceRetry: false);
            }),
            $dispatcher,
            $clock,
            new InstagramExecutionBudget($clock, 120),
        );

        $firstAttempt = $consumer->consume(5, 150);

        self::assertSame(0, $firstAttempt->handled);
        self::assertSame(1, $firstAttempt->failed);
        self::assertSame(1, $firstAttempt->retried);
        self::assertSame(0, $firstAttempt->terminalFailed());
        self::assertCount(1, $instagramTransport->getRejected());
        self::assertCount(0, $failedTransport->getSent());
        self::assertSame($baselineListenerCounts, $this->listenerCounts($dispatcher));

        // DelayStamp(0) becomes available just after its dispatch timestamp.
        $clock->sleep(0.001);
        $terminalAttempt = $consumer->consume(5, 150);

        self::assertSame(0, $terminalAttempt->handled);
        self::assertSame(1, $terminalAttempt->failed);
        self::assertSame(0, $terminalAttempt->retried);
        self::assertSame(1, $terminalAttempt->terminalFailed());
        self::assertCount(2, $instagramTransport->getRejected());
        self::assertCount(1, $failedTransport->getSent());
        self::assertSame($baselineListenerCounts, $this->listenerCounts($dispatcher));
    }

    public function testItRejectsUnboundedHttpLimits(): void
    {
        $clock = new MockClock();
        $consumer = $this->consumer(
            new InMemoryTransport(clock: $clock),
            new CallbackMessageBus(static fn (Envelope $envelope): Envelope => $envelope),
            new EventDispatcher(),
            $clock,
            new InstagramExecutionBudget($clock, 120),
        );

        $this->expectException(\InvalidArgumentException::class);
        $consumer->consume(InstagramMessageConsumer::MAX_MESSAGE_LIMIT + 1, 150);
    }

    public function testItAlwaysClearsBudgetAndListenersWhenReceiverFails(): void
    {
        $clock = new MockClock();
        $dispatcher = new EventDispatcher();
        $budget = new InstagramExecutionBudget($clock, 120);
        $consumer = $this->consumer(
            new FailingReceiver(),
            new CallbackMessageBus(static fn (Envelope $envelope): Envelope => $envelope),
            $dispatcher,
            $clock,
            $budget,
        );

        try {
            $consumer->consume(1, 150);
            self::fail('The receiver exception should escape the HTTP consumer.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Receiver unavailable.', $exception->getMessage());
        }

        self::assertFalse($budget->isActive());
        self::assertSame([], $dispatcher->getListeners(WorkerRunningEvent::class));
        self::assertSame([], $dispatcher->getListeners(WorkerMessageHandledEvent::class));
        self::assertSame([], $dispatcher->getListeners(WorkerMessageFailedEvent::class));
        self::assertSame([], $dispatcher->getListeners(WorkerMessageRetriedEvent::class));
    }

    private function consumer(
        ReceiverInterface $transport,
        MessageBusInterface $bus,
        EventDispatcher $dispatcher,
        MockClock $clock,
        InstagramExecutionBudget $budget,
    ): InstagramMessageConsumer {
        return new InstagramMessageConsumer(
            $transport,
            $bus,
            $dispatcher,
            $clock,
            $budget,
            new NullLogger(),
        );
    }

    /** @return array<class-string, int> */
    private function listenerCounts(EventDispatcher $dispatcher): array
    {
        return [
            WorkerRunningEvent::class => count($dispatcher->getListeners(WorkerRunningEvent::class)),
            WorkerMessageHandledEvent::class => count($dispatcher->getListeners(WorkerMessageHandledEvent::class)),
            WorkerMessageFailedEvent::class => count($dispatcher->getListeners(WorkerMessageFailedEvent::class)),
            WorkerMessageRetriedEvent::class => count($dispatcher->getListeners(WorkerMessageRetriedEvent::class)),
        ];
    }
}

final class FailingReceiver implements ReceiverInterface
{
    public function get(): iterable
    {
        throw new \RuntimeException('Receiver unavailable.');
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }
}

final readonly class ConsumerProbeMessage
{
    public function __construct(public int $id)
    {
    }
}

final class CallbackMessageBus implements MessageBusInterface
{
    /** @param Closure(Envelope): Envelope $callback */
    public function __construct(private readonly Closure $callback)
    {
    }

    /** @param list<StampInterface> $stamps */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return ($this->callback)(Envelope::wrap($message, $stamps));
    }
}
