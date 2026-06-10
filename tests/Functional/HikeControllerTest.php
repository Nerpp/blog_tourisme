<?php

namespace App\Tests\Functional;

use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use DOMDocument;
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
        self::assertSelectorTextContains('body', 'Télécharger le GPX');
        self::assertSelectorTextContains('body', 'Aller au départ');
        self::assertSelectorTextContains('body', 'Point randonnée 1');
        self::assertSelectorTextContains('body', 'Point randonnée 2');
        self::assertSelectorTextContains('body', 'Le tracé relie les étapes enregistrées. Il ne remplace pas une trace GPS complète.');
        self::assertSelectorTextContains('body', 'Le fichier GPX contient les étapes enregistrées. Il ne remplace pas une trace GPS complète.');

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
        self::assertSelectorTextNotContains('body', 'Télécharger le GPX');
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
        self::assertSelectorTextNotContains('body', 'Télécharger le GPX');
        self::assertSelectorTextNotContains('body', 'Parcours Google Maps');
        self::assertSelectorTextContains('body', 'Aucune étape détaillée pour le moment.');
        self::assertCount(0, $crawler->filter('[data-public-hike-map]'));
    }

    public function testGpxIsNotAvailableWithOnlyStartPoint(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $startPoint = $this->createHikePoint($hike, 42.7000, 2.9000, 1);
        $startPoint->setType(HikePointType::Start);
        $this->persistAndFlush($startPoint);

        $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Télécharger le GPX');

        $client->request('GET', sprintf('/randonnees/%s/gpx', $hike->getSlug()));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('<gpx', (string) $client->getResponse()->getContent());
    }

    public function testGpxIsAvailableWithStartAndInterestPoint(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $hike->setTitle('Randonnée GPX & sécurité');
        $startPoint = $this->createHikePoint($hike, 42.7000, 2.9000, 1);
        $startPoint
            ->setType(HikePointType::Start)
            ->setTitle('Départ & lac "nord"');
        $interestPoint = $this->createHikePoint($hike, 42.7040, 2.9060, 2);
        $interestPoint
            ->setType(HikePointType::Interest)
            ->setTitle('Point d’intérêt & belvédère');
        $this->persistAndFlush($hike, $startPoint, $interestPoint);

        $crawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="/randonnees/%s/gpx"]:contains("Télécharger le GPX")', $hike->getSlug()))->count());

        $client->request('GET', sprintf('/randonnees/%s/gpx', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/gpx+xml; charset=UTF-8');
        self::assertStringContainsString('attachment;', $client->getResponse()->headers->get('Content-Disposition') ?? '');
        self::assertStringContainsString(sprintf('randonnee-%s.gpx', $hike->getSlug()), $client->getResponse()->headers->get('Content-Disposition') ?? '');

        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<gpx', $xml);
        self::assertStringContainsString('version="1.1"', $xml);
        self::assertStringNotContainsString('<trk', $xml);

        $document = $this->parseXml($xml);
        self::assertSame(2, $document->getElementsByTagName('wpt')->length);
        self::assertSame(1, $document->getElementsByTagName('rte')->length);
        self::assertSame(2, $document->getElementsByTagName('rtept')->length);
        self::assertSame('Départ & lac "nord"', $document->getElementsByTagName('wpt')->item(0)?->getElementsByTagName('name')->item(0)?->textContent);
        self::assertSame('Point d’intérêt & belvédère', $document->getElementsByTagName('wpt')->item(1)?->getElementsByTagName('name')->item(0)?->textContent);
        self::assertSame('Étape 1 - Départ & lac "nord"', $document->getElementsByTagName('rtept')->item(0)?->getElementsByTagName('name')->item(0)?->textContent);
        self::assertSame('Étape 2 - Point d’intérêt & belvédère', $document->getElementsByTagName('rtept')->item(1)?->getElementsByTagName('name')->item(0)?->textContent);
    }

    public function testGpxIsAvailableWithTwoOrderedGpsPointsWithoutExplicitStart(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());
        $this->createHikePoint($hike, 42.7040, 2.9060, 2);
        $this->createHikePoint($hike, 42.7000, 2.9000, 1);

        $crawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a:contains("Télécharger le GPX")')->count());

        $client->request('GET', sprintf('/randonnees/%s/gpx', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        $document = $this->parseXml((string) $client->getResponse()->getContent());
        self::assertSame('42.7', $document->getElementsByTagName('rtept')->item(0)?->getAttribute('lat'));
        self::assertSame('42.704', $document->getElementsByTagName('rtept')->item(1)?->getAttribute('lat'));
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

    private function parseXml(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded);

        return $document;
    }
}
