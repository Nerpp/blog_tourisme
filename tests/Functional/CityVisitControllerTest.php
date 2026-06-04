<?php

namespace App\Tests\Functional;

use App\Enum\CityVisitDraftStatus;
use App\Enum\DestinationType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CityVisitControllerTest extends FunctionalTestCase
{
    public function testPublishedCityVisitIsAccessibleWithoutMedia(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Ville visite publique', DestinationType::City);
        $cityVisit = $this->createPublishedCityVisit($this->createVerifiedAdmin(), $destination);

        $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $cityVisit->getTitle());
    }

    public function testUnknownCityVisitReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', '/visites-de-ville/visite-inconnue-fonctionnelle');
    }

    public function testDraftCityVisitIsNotVisiblePublicly(): void
    {
        $client = static::createClient();
        $cityVisit = $this->createCityVisitDraft($this->createVerifiedAdmin());
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));
    }

    public function testGeographicDestinationWithoutEditorialDestinationDoesNotBreakShowPage(): void
    {
        $client = static::createClient();
        $city = $this->createDestination('Commune visite geo', DestinationType::City, code: '66002');
        $cityVisit = $this->createPublishedCityVisit($this->createVerifiedAdmin());
        $cityVisit
            ->setDestination(null)
            ->setGeographicDestination($city)
            ->setDetectedCommuneName('Commune visite geo')
            ->setStatus(CityVisitDraftStatus::Finished);
        $this->persistAndFlush($cityVisit);

        $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Commune visite geo');
    }
}
