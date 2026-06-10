<?php

namespace App\Tests\Functional;

use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HikeControllerTest extends FunctionalTestCase
{
    public function testPublishedHikeIsAccessibleWithoutMedia(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Massif rando public', DestinationType::Area);
        $hike = $this->createPublishedHike($this->createVerifiedAdmin(), $destination);

        $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $hike->getTitle());
    }

    public function testPublishedHikeWithSeveralGpsPointsShowsInternalRouteMap(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $firstPoint = $this->createHikePoint($hike, 42.7000, 2.9000, 1);
        $secondPoint = $this->createHikePoint($hike, 42.7040, 2.9060, 2);
        $thirdPoint = $this->createHikePoint($hike, 42.7080, 2.9120, 3);

        $crawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Parcours Google Maps');
        self::assertSelectorTextContains('body', 'Voir le parcours');
        self::assertSelectorTextContains('body', 'Aller au départ');
        self::assertSelectorTextContains('body', 'Point randonnée 1');
        self::assertSelectorTextContains('body', 'Point randonnée 2');
        self::assertSelectorTextContains('body', 'Le tracé relie les étapes enregistrées. Il ne remplace pas une trace GPS complète.');

        $startLink = $crawler->filter('a:contains("Aller au départ")')->first();
        self::assertStringStartsWith('https://www.google.com/maps/dir/?', $startLink->attr('href') ?? '');
        self::assertStringContainsString('42.7,2.9', $startLink->attr('href') ?? '');

        $map = $crawler->filter('[data-public-hike-map]');
        self::assertCount(1, $map);
        $points = json_decode($map->attr('data-points') ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $points);
        self::assertSame('Point randonnée 1', $points[0]['title'] ?? null);
        self::assertSame(42.7, $points[0]['latitude'] ?? null);
        self::assertSame([
            $firstPoint->getId(),
            $secondPoint->getId(),
            $thirdPoint->getId(),
        ], array_column($points, 'id'));
        self::assertGreaterThan(0, $crawler->filter('a:contains("Ouvrir la zone dans OpenStreetMap")')->count());

        $pointLinks = $crawler->filter('a[data-hike-map-focus]:contains("Voir ce point")');
        self::assertCount(3, $pointLinks);
        foreach ($pointLinks as $index => $link) {
            $href = $link->getAttribute('href');
            self::assertSame(sprintf('#hike-gallery-%d-route-map', $hike->getId()), $href);
            self::assertSame((string) $index, $link->getAttribute('data-point-index'));
            self::assertNotSame('', $link->getAttribute('data-point-id'));
            self::assertStringNotContainsString('google.com/maps', $href);
        }
    }

    public function testPublishedHikeWithOneGpsPointShowsMapWithoutRouteWarning(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $this->createHikePoint($hike, 42.7000, 2.9000, 1);

        $crawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Voir le parcours');
        self::assertSelectorTextContains('body', 'La carte affiche le point GPS enregistré pour cette randonnée.');
        self::assertSelectorTextNotContains('body', 'Le tracé relie les étapes enregistrées.');

        $map = $crawler->filter('[data-public-hike-map]');
        self::assertCount(1, $map);
        $points = json_decode($map->attr('data-points') ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $points);
        self::assertCount(1, $crawler->filter('a[data-hike-map-focus]:contains("Voir ce point")'));
    }

    public function testPublishedHikeWithoutGpsPointDoesNotShowRouteMapAction(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Voir le parcours');
        self::assertSelectorTextNotContains('body', 'Parcours Google Maps');
        self::assertSelectorTextContains('body', 'Aucune étape détaillée pour le moment.');
        self::assertCount(0, $crawler->filter('[data-public-hike-map]'));
    }

    public function testUnknownHikeReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', '/randonnees/randonnee-inconnue-fonctionnelle');
    }

    public function testDraftHikeIsNotVisiblePublicly(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createVerifiedAdmin());
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));
    }

    public function testGeographicDestinationWithoutEditorialDestinationDoesNotBreakShowPage(): void
    {
        $client = static::createClient();
        $city = $this->createDestination('Commune rando geo', DestinationType::City, code: '66001');
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $hike
            ->setDestination(null)
            ->setGeographicDestination($city)
            ->setDetectedCommuneName('Commune rando geo')
            ->setStatus(HikeDraftStatus::Finished);
        $this->persistAndFlush($hike);

        $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Commune rando geo');
    }
}
