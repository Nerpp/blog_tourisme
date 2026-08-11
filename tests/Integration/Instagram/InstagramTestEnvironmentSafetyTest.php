<?php

namespace App\Tests\Integration\Instagram;

use App\Service\Instagram\InstagramPublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class InstagramTestEnvironmentSafetyTest extends KernelTestCase
{
    public function testTheTestContainerUsesAFailClosedMockClient(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $publisher = $container->get(InstagramPublisher::class);
        self::assertInstanceOf(InstagramPublisher::class, $publisher);
        $clientProperty = new \ReflectionProperty($publisher, 'httpClient');
        self::assertInstanceOf(MockHttpClient::class, $clientProperty->getValue($publisher));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('appel HTTP Instagram inattendu a été bloqué');
        $publisher->createImageContainer('https://estela-exploration.fr/uploads/media/test.webp', 'Test');
    }
}
