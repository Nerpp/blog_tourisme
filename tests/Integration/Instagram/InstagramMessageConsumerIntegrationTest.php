<?php

namespace App\Tests\Integration\Instagram;

use App\Message\PublishInstagramContent;
use App\Service\Instagram\InstagramExecutionBudget;
use App\Service\Instagram\InstagramMessageConsumer;
use App\Service\Instagram\InstagramWebCronProcessor;
use App\Service\Instagram\InstagramWebCronProcessorInterface;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InstagramMessageConsumerIntegrationTest extends IntegrationTestCase
{
    public function testContainerWiringConsumesOnlyInstagramAsyncAndLeavesFailedTransportUntouched(): void
    {
        $consumer = $this->service(InstagramMessageConsumer::class);
        $processor = $this->service(InstagramWebCronProcessorInterface::class);
        $executionBudget = $this->service(InstagramExecutionBudget::class);
        $eventDispatcher = $this->service(EventDispatcherInterface::class);
        $instagramTransport = $this->service('messenger.transport.instagram_async');
        $failedTransport = $this->service('messenger.transport.failed');
        self::assertInstanceOf(InstagramMessageConsumer::class, $consumer);
        self::assertInstanceOf(InstagramWebCronProcessor::class, $processor);
        self::assertInstanceOf(InstagramExecutionBudget::class, $executionBudget);
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        self::assertInstanceOf(InMemoryTransport::class, $instagramTransport);
        self::assertInstanceOf(InMemoryTransport::class, $failedTransport);
        $listenerCounts = $this->listenerCounts($eventDispatcher);
        $instagramTransport->reset();
        $failedTransport->reset();
        $failedTransport->send(new Envelope(new PublishInstagramContent(
            1,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        )));

        $result = $consumer->consume(1, 240);

        self::assertSame(0, $result->processed());
        self::assertCount(0, $instagramTransport->getAcknowledged());
        self::assertCount(0, $instagramTransport->getRejected());
        self::assertCount(1, $failedTransport->getSent());
        self::assertCount(0, $failedTransport->getAcknowledged());
        self::assertCount(0, $failedTransport->getRejected());
        self::assertFalse($executionBudget->isActive());
        self::assertSame($listenerCounts, $this->listenerCounts($eventDispatcher));
    }

    /** @return array<class-string, int> */
    private function listenerCounts(EventDispatcherInterface $dispatcher): array
    {
        return [
            WorkerRunningEvent::class => count($dispatcher->getListeners(WorkerRunningEvent::class)),
            WorkerMessageHandledEvent::class => count($dispatcher->getListeners(WorkerMessageHandledEvent::class)),
            WorkerMessageFailedEvent::class => count($dispatcher->getListeners(WorkerMessageFailedEvent::class)),
            WorkerMessageRetriedEvent::class => count($dispatcher->getListeners(WorkerMessageRetriedEvent::class)),
        ];
    }
}
