<?php

namespace App\Tests\Integration\Facebook;

use App\Message\PublishFacebookContent;
use App\Service\Facebook\FacebookPublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FacebookMessengerConfigurationTest extends KernelTestCase
{
    public function testPublishFacebookContentIsRoutedOnlyToTheInMemoryFacebookTransport(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $facebookTransport = $container->get('messenger.transport.facebook_async');
        $instagramTransport = $container->get('messenger.transport.instagram_async');
        $messageBus = $container->get('messenger.default_bus');
        self::assertInstanceOf(InMemoryTransport::class, $facebookTransport);
        self::assertInstanceOf(InMemoryTransport::class, $instagramTransport);
        self::assertInstanceOf(MessageBusInterface::class, $messageBus);
        $facebookTransport->reset();
        $instagramTransport->reset();

        $messageBus->dispatch(new PublishFacebookContent(
            42,
            '0123456789abcdef0123456789abcdef',
        ));

        self::assertCount(1, $facebookTransport->getSent());
        self::assertCount(0, $instagramTransport->getSent());
        self::assertInstanceOf(
            PublishFacebookContent::class,
            $facebookTransport->getSent()[0]->getMessage(),
        );
    }

    public function testTheTestContainerUsesAFailClosedFacebookClient(): void
    {
        self::bootKernel();
        $publisher = static::getContainer()->get(FacebookPublisher::class);
        self::assertInstanceOf(FacebookPublisher::class, $publisher);
        $clientProperty = new \ReflectionProperty($publisher, 'httpClient');
        self::assertInstanceOf(MockHttpClient::class, $clientProperty->getValue($publisher));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('appel HTTP Facebook inattendu a été bloqué');
        $publisher->publishLink(
            'Test sans réseau.',
            'https://estela-exploration.fr/randonnees/test',
        );
    }
}
