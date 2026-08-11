<?php

namespace App\Tests\Unit\Instagram;

use App\Repository\InstagramPublicationRepository;
use App\Service\Instagram\InstagramExecutionBudget;
use App\Service\Instagram\InstagramMessageConsumer;
use App\Service\Instagram\InstagramPublicationReconciler;
use App\Service\Instagram\InstagramPublicationScheduler;
use App\Service\Instagram\InstagramWebCronProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class InstagramWebCronProcessorTest extends TestCase
{
    public function testReconciliationAndConsumptionShareOneWallClockDeadline(): void
    {
        $clock = new MockClock('2026-08-11T12:00:00+02:00');
        $budget = new InstagramExecutionBudget($clock, 120);
        $publicationRepository = $this->createMock(InstagramPublicationRepository::class);
        $publicationRepository
            ->expects(self::once())
            ->method('findRecoverableForDispatch')
            ->willReturnCallback(
                /** @return list<\App\Entity\InstagramPublication> */
                static function () use ($clock): array {
                    $clock->sleep(130);

                    return [];
                },
            );
        $scheduler = (new ReflectionClass(InstagramPublicationScheduler::class))
            ->newInstanceWithoutConstructor();
        self::assertInstanceOf(InstagramPublicationScheduler::class, $scheduler);
        $reconciler = new InstagramPublicationReconciler(
            $publicationRepository,
            $scheduler,
            new LockFactory(new InMemoryStore()),
        );
        $receiver = new BudgetProbeReceiver();
        $consumer = new InstagramMessageConsumer(
            $receiver,
            new BudgetProbeBus(),
            new EventDispatcher(),
            $clock,
            $budget,
            new NullLogger(),
        );
        $processor = new InstagramWebCronProcessor(
            $reconciler,
            $consumer,
            $budget,
            $clock,
            new NullLogger(),
            5,
            240,
        );

        $processor->run();

        self::assertSame(0, $receiver->getCalls, 'Aucune enveloppe ne doit démarrer avec moins de 120 s restantes.');
        self::assertSame('2026-08-11T12:02:10+02:00', $clock->now()->format('c'));
        self::assertFalse($budget->isActive());
    }
}

final class BudgetProbeReceiver implements ReceiverInterface
{
    public int $getCalls = 0;

    public function get(): iterable
    {
        ++$this->getCalls;

        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }
}

final class BudgetProbeBus implements MessageBusInterface
{
    /** @param list<StampInterface> $stamps */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return Envelope::wrap($message, $stamps);
    }
}
