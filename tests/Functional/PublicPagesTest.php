<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Enum\DestinationType;
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

    public function testHomepageCardsRenderThumbOnlyWebpWithoutResponsiveCandidates(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $hike->setFinishedAt(new \DateTimeImmutable('+10 years'));
        $media = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy([
            'title' => 'Vue de Collioure',
        ]);
        self::assertInstanceOf(MediaAsset::class, $media);
        $variants = $media->getVariants();
        self::assertIsArray($variants);
        self::assertIsArray($variants['thumb'] ?? null);
        unset($variants['thumb']['width'], $variants['thumb']['height']);
        $media->setVariants($variants);
        $this->persistAndFlush($hike);
        $this->linkHikeMedia($hike, $media, MediaRole::Cover);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $destinationImage = $crawler->filter('img.home-destination-card__img')->first();
        self::assertSame(1, $destinationImage->count());
        self::assertStringEndsWith('_thumb.webp', (string) $destinationImage->attr('src'));
        self::assertNull($destinationImage->attr('srcset'));
        self::assertNull($destinationImage->attr('sizes'));
        self::assertStringNotContainsString('_mobile.webp', $destinationImage->outerHtml());
        self::assertStringNotContainsString('_medium.webp', $destinationImage->outerHtml());
        self::assertStringNotContainsString('_large.webp', $destinationImage->outerHtml());
        self::assertSame(0, $crawler->filter('.home-destination-card picture')->count());
        self::assertSame('lazy', $destinationImage->attr('loading'));
        self::assertSame('async', $destinationImage->attr('decoding'));
        self::assertNull($destinationImage->attr('fetchpriority'));
        self::assertSame('600', $destinationImage->attr('width'));
        self::assertSame('338', $destinationImage->attr('height'));

        $heroImage = $crawler->filter('img.home-hero__image')->first();
        self::assertSame(1, $heroImage->count());
        self::assertNotSame('lazy', $heroImage->attr('loading'));
        self::assertSame('high', $heroImage->attr('fetchpriority'));

        $latestImage = $crawler->filter('article.home-latest-card img.home-latest-card__image')->first();
        self::assertSame(1, $latestImage->count());
        self::assertStringEndsWith('_thumb.webp', (string) $latestImage->attr('src'));
        self::assertNull($latestImage->attr('srcset'));
        self::assertNull($latestImage->attr('sizes'));
        self::assertStringNotContainsString('_mobile.webp', $latestImage->outerHtml());
        self::assertStringNotContainsString('_medium.webp', $latestImage->outerHtml());
        self::assertStringNotContainsString('_large.webp', $latestImage->outerHtml());
        self::assertSame(0, $crawler->filter('article.home-latest-card picture')->count());
        self::assertSame('eager', $latestImage->attr('loading'));
        self::assertNull($latestImage->attr('fetchpriority'));
    }

    public function testHomepageDestinationAndLatestCardsUseTheCommonImageFallback(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Destination sans image accueil', DestinationType::Area);
        $hike = $this->createPublishedHike($this->createVerifiedAdmin(), $destination);
        $hike->setFinishedAt(new \DateTimeImmutable('+20 years'));
        $this->persistAndFlush($hike);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $destinationCard = $crawler->filter(sprintf(
            '.home-destination-card[href="/destinations/%s"]',
            $destination->getSlug(),
        ));
        self::assertSame(
            '/images/placeholders/destination-card-placeholder.webp',
            $destinationCard->filter('img.home-destination-card__img')->attr('src'),
        );
        self::assertStringContainsString(
            'destination-card-placeholder-480.webp 480w',
            (string) $destinationCard->filter('img.home-destination-card__img')->attr('srcset'),
        );
        self::assertSame(
            '(min-width: 1180px) 360px, (min-width: 760px) 50vw, 100vw',
            $destinationCard->filter('img.home-destination-card__img')->attr('sizes'),
        );
        self::assertSame(0, $destinationCard->filter('picture')->count());
        self::assertSame(
            '/images/placeholders/destination-card-placeholder.webp',
            $crawler->filter('.home-latest-card img.home-latest-card__image')->attr('src'),
        );
        self::assertStringContainsString(
            'destination-card-placeholder-960.webp 960w',
            (string) $crawler->filter('.home-latest-card img.home-latest-card__image')->attr('srcset'),
        );
        self::assertSame(
            '(min-width: 1180px) 420px, (min-width: 760px) 45vw, 100vw',
            $crawler->filter('.home-latest-card img.home-latest-card__image')->attr('sizes'),
        );
        self::assertSame(0, $crawler->filter('.home-latest-card picture')->count());
    }

    public function testLatestPublishedArticleWithoutMediaUsesHomepagePlaceholder(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $article
            ->setTitle('Dernier article sans image accueil')
            ->setPublishedAt(new \DateTimeImmutable('+30 years'));
        $this->persistAndFlush($article);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $latestCard = $crawler->filter('article.home-latest-card');
        self::assertStringContainsString('Dernier article sans image accueil', $latestCard->text());
        self::assertSame(
            '/images/placeholders/destination-card-placeholder.webp',
            $latestCard->filter('img.home-latest-card__image')->attr('src'),
        );
    }

    public function testPlaceholderPathIsNeverPersistedAsMediaData(): void
    {
        $mediaAssets = $this->entityManager()->getRepository(MediaAsset::class)->findAll();

        foreach ($mediaAssets as $mediaAsset) {
            self::assertInstanceOf(MediaAsset::class, $mediaAsset);
            $storedMediaData = json_encode([
                $mediaAsset->getFilePath(),
                $mediaAsset->getThumbnailPath(),
                $mediaAsset->getExternalUrl(),
                $mediaAsset->getMetadata(),
                $mediaAsset->getVariants(),
            ], JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString('destination-card-placeholder.webp', $storedMediaData);
        }
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
