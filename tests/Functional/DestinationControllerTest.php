<?php

namespace App\Tests\Functional;

use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;

final class DestinationControllerTest extends FunctionalTestCase
{
    public function testDestinationIndexListsRootDestinations(): void
    {
        $client = static::createClient();
        $this->createDestination('France fonctionnelle', DestinationType::Country);

        $client->request('GET', '/destinations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Destinations');
    }

    public function testDestinationShowIsAccessible(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Massif fonctionnel', DestinationType::Area);

        $client->request('GET', sprintf('/destinations/%s', $destination->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $destination->getName());
    }

    public function testUnknownDestinationReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $client->request('GET', '/destinations/destination-fonctionnelle-inconnue');
    }

    public function testGeographicCityContentDoesNotRequireEditorialDestination(): void
    {
        $client = static::createClient();
        $country = $this->createDestination('France geo', DestinationType::Country);
        $region = $this->createDestination('Occitanie geo', DestinationType::Region, $country);
        $department = $this->createDestination('Pyrenees-Orientales geo', DestinationType::Department, $region, '66');
        $city = $this->createDestination('Paris geo commune', DestinationType::City, $department, '66136');
        $editorialArea = $this->createDestination('Massif geo editorial', DestinationType::Area, $department);
        $hike = $this->createHikeDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']), null);
        $hike
            ->setTitle('Randonnée seulement géographique')
            ->setStatus(HikeDraftStatus::Finished)
            ->setDestination(null)
            ->setGeographicDestination($city)
            ->setFinishedAt(new \DateTimeImmutable('-2 hours'));
        $this->persistAndFlush($hike);

        $client->request('GET', sprintf('/destinations/%s', $city->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Randonnée seulement géographique');
        self::assertSelectorTextContains('body', (string) $city->getName());

        $client->request('GET', sprintf('/destinations/%s', $editorialArea->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Randonnée seulement géographique', $client->getResponse()->getContent() ?: '');
    }
}
