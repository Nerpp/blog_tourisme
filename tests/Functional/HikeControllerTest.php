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
