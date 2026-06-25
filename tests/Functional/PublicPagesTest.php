<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Enum\MediaRole;
use Symfony\Component\Routing\RouterInterface;

final class PublicPagesTest extends FunctionalTestCase
{
    public function testHomePageIsReachable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testHomepageCardsRenderResponsiveWebpWithoutStandardJpegFallback(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $hike->setFinishedAt(new \DateTimeImmutable('+10 years'));
        $media = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy([
            'title' => 'Vue de Collioure',
        ]);
        self::assertInstanceOf(MediaAsset::class, $media);
        $this->persistAndFlush($hike);
        $this->linkHikeMedia($hike, $media, MediaRole::Cover);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $destinationImage = $crawler->filter('img.home-destination-card__img[srcset]')->first();
        self::assertSame(1, $destinationImage->count());
        self::assertStringEndsWith('_mobile.webp', (string) $destinationImage->attr('src'));
        self::assertStringContainsString(' 600w', (string) $destinationImage->attr('srcset'));
        self::assertStringContainsString(' 960w', (string) $destinationImage->attr('srcset'));
        self::assertStringContainsString(' 1600w', (string) $destinationImage->attr('srcset'));
        self::assertStringNotContainsString('.jpg', $destinationImage->outerHtml());
        self::assertStringContainsString('calc(100vw - 24px)', (string) $destinationImage->attr('sizes'));
        self::assertSame('lazy', $destinationImage->attr('loading'));

        $latestImage = $crawler->filter('article.home-latest-card img.home-latest-card__image[srcset]')->first();
        self::assertSame(1, $latestImage->count());
        self::assertStringEndsWith('_mobile.webp', (string) $latestImage->attr('src'));
        self::assertStringContainsString(' 960w', (string) $latestImage->attr('srcset'));
        self::assertStringNotContainsString('.jpg', $latestImage->outerHtml());
        self::assertSame('eager', $latestImage->attr('loading'));
    }

    public function testLoginPageIsReachable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
    }

    public function testRegisterPageIsReachable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
    }

    public function testLogoutRouteAllowsOnlyPost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame(['POST'], $router->getRouteCollection()->get('app_logout')?->getMethods());
    }
}
