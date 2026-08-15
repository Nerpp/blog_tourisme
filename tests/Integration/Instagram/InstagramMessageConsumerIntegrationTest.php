<?php

namespace App\Tests\Integration\Instagram;

use App\Message\PublishFacebookContent;
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
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InstagramMessageConsumerIntegrationTest extends IntegrationTestCase
{
    public function testContainerWiringConsumesBothSocialQueuesAndLeavesFailedTransportUntouched(): void
    {
        $consumer = $this->service(InstagramMessageConsumer::class);
        $processor = $this->service(InstagramWebCronProcessorInterface::class);
        $executionBudget = $this->service(InstagramExecutionBudget::class);
        $eventDispatcher = $this->service(EventDispatcherInterface::class);
        $instagramTransport = $this->service('messenger.transport.instagram_async');
        $facebookTransport = $this->service('messenger.transport.facebook_async');
        $failedTransport = $this->service('messenger.transport.failed');
        self::assertInstanceOf(InstagramMessageConsumer::class, $consumer);
        self::assertInstanceOf(InstagramWebCronProcessor::class, $processor);
        self::assertInstanceOf(InstagramExecutionBudget::class, $executionBudget);
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        self::assertInstanceOf(InMemoryTransport::class, $instagramTransport);
        self::assertInstanceOf(InMemoryTransport::class, $facebookTransport);
        self::assertInstanceOf(InMemoryTransport::class, $failedTransport);
        $listenerCounts = $this->listenerCounts($eventDispatcher);
        $instagramTransport->reset();
        $facebookTransport->reset();
        $failedTransport->reset();
        $instagramTransport->send(new Envelope(new PublishInstagramContent(
            9_991_001,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        )));
        $facebookTransport->send(new Envelope(new PublishFacebookContent(
            9_991_002,
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        )));
        $failedTransport->send(new Envelope(new PublishInstagramContent(
            1,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        )));

        $result = $consumer->consume(2, 240);

        self::assertSame(2, $result->processed());
        self::assertCount(1, $instagramTransport->getAcknowledged());
        self::assertCount(0, $instagramTransport->getRejected());
        self::assertCount(1, $facebookTransport->getAcknowledged());
        self::assertCount(0, $facebookTransport->getRejected());
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
            WorkerMessageReceivedEvent::class => count($dispatcher->getListeners(WorkerMessageReceivedEvent::class)),
            WorkerMessageHandledEvent::class => count($dispatcher->getListeners(WorkerMessageHandledEvent::class)),
            WorkerMessageFailedEvent::class => count($dispatcher->getListeners(WorkerMessageFailedEvent::class)),
            WorkerMessageRetriedEvent::class => count($dispatcher->getListeners(WorkerMessageRetriedEvent::class)),
        ];
    }
}
