<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Enum\DestinationType;

final class QuickDestinationControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromQuickDestinationCreate(): void
    {
        $client = static::createClient();

        $client->request('POST', '/admin/studio/destinations/quick-create');

        self::assertResponseRedirects('/login');
    }

    public function testQuickDestinationCreateRequiresCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => 'bad-token',
            'name' => 'Destination rapide csrf invalide',
            'type' => DestinationType::Area->value,
        ]);

        self::assertResponseRedirects('/admin');
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide csrf invalide']));
    }

    public function testVerifiedAdminCanCreateQuickDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->tokenFromFormAction($crawler, '/admin/studio/destinations/quick-create'),
            'name' => 'Destination rapide fonctionnelle',
            'type' => DestinationType::Area->value,
            'returnUrl' => '/admin/studio',
        ]);

        self::assertResponseRedirects('/admin/studio');
        $destination = $this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide fonctionnelle']);
        self::assertInstanceOf(Destination::class, $destination);
        self::assertSame(DestinationType::Area, $destination->getType());
    }

    public function testVerifiedAdminCanCreateDistanceHikeWithCommuneWithoutPrecisePoint(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/quick?type=hike&mode=distance');
        self::assertResponseIsSuccessful();

        $commune = 'Bors '.$this->uniqueToken('commune');
        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->tokenFromFormAction($crawler, '/admin/studio/destinations/quick-create'),
            'contextType' => 'quick_hike',
            'targetType' => 'quick_hike',
            'type' => DestinationType::City->value,
            'name' => $commune,
            'countryName' => 'France',
            'regionName' => 'Nouvelle-Aquitaine',
            'departmentName' => 'Charente',
            'departmentCode' => '16',
            'cityName' => $commune,
            'code' => '16050',
            'postalCode' => '16190',
            'communeCenterLatitude' => '45.3631000',
            'communeCenterLongitude' => '0.0607000',
            'returnUrl' => '/admin/quick?type=hike&mode=distance',
        ]);

        $hike = $this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['title' => 'Randonnée à '.$commune]);
        self::assertInstanceOf(HikeDraft::class, $hike);
        self::assertNull($hike->getDestination());
        self::assertInstanceOf(Destination::class, $hike->getGeographicDestination());
        self::assertSame('16050', $hike->getGeographicDestination()->getCode());
        self::assertSame(45.3631, $hike->getGeographicDestination()->getLatitude());
        self::assertCount(0, $hike->getPoints());
        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
    }

    public function testVerifiedAdminCanCreateDistanceCityVisitWithValidatedPrecisePoint(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/quick?type=city_visit&mode=distance');
        self::assertResponseIsSuccessful();

        $commune = 'Ville '.$this->uniqueToken('commune');
        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->tokenFromFormAction($crawler, '/admin/studio/destinations/quick-create'),
            'contextType' => 'quick_city_visit',
            'targetType' => 'quick_city_visit',
            'type' => DestinationType::City->value,
            'name' => $commune,
            'countryName' => 'France',
            'regionName' => 'Occitanie',
            'departmentName' => 'Pyrenees-Orientales',
            'departmentCode' => '66',
            'cityName' => $commune,
            'code' => '66136',
            'postalCode' => '66000',
            'communeCenterLatitude' => '42.6986000',
            'communeCenterLongitude' => '2.8956000',
            'latitude' => '42.7023456',
            'longitude' => '2.9012345',
            'returnUrl' => '/admin/quick?type=city_visit&mode=distance',
        ]);

        $cityVisit = $this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => 'Visite de ville à '.$commune]);
        self::assertInstanceOf(CityVisitDraft::class, $cityVisit);
        self::assertNull($cityVisit->getDestination());
        self::assertInstanceOf(Destination::class, $cityVisit->getGeographicDestination());
        self::assertSame('66136', $cityVisit->getGeographicDestination()->getCode());
        self::assertSame(42.6986, $cityVisit->getGeographicDestination()->getLatitude());
        self::assertCount(1, $cityVisit->getPoints());
        $point = $cityVisit->getPoints()->first();
        self::assertNotFalse($point);
        self::assertSame(42.7023456, $point->getLatitude());
        self::assertSame(2.9012345, $point->getLongitude());
        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
    }
}
