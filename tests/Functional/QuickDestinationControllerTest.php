<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\Place;
use App\Enum\DestinationType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class QuickDestinationControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromQuickDestinationCreate(): void
    {
        $client = static::createClient();

        $client->request('POST', '/admin/studio/destinations/quick-create');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromQuickDestinationCreate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => 'irrelevant-for-denied-access',
            'name' => 'Destination rapide utilisateur refuse',
            'type' => DestinationType::Area->value,
        ]);

        self::assertResponseRedirects('/');
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide utilisateur refuse']));
    }

    public function testUnverifiedAdminIsRejectedFromQuickDestinationCreate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUnverifiedAdmin());

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => 'irrelevant-for-denied-access',
            'name' => 'Destination rapide admin non verifie',
            'type' => DestinationType::Area->value,
        ]);

        self::assertResponseRedirects('/');
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide admin non verifie']));
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

    public function testQuickDestinationCreateRejectsEmptyJsonPayload(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['ok' => false, 'message' => 'Renseignez au moins le pays, la région ou le lieu.'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
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
            'contextType' => 'hike',
            'contextId' => $hike->getId(),
            'targetType' => 'hike',
            'targetId' => $hike->getId(),
            'name' => 'Destination rapide fonctionnelle',
            'type' => DestinationType::Area->value,
            'returnUrl' => '/admin/studio',
        ]);

        self::assertResponseRedirects('/admin/studio');
        $destination = $this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide fonctionnelle']);
        self::assertInstanceOf(Destination::class, $destination);
        self::assertSame(DestinationType::Area, $destination->getType());
        $storedHike = $this->refresh($hike);
        self::assertInstanceOf(HikeDraft::class, $storedHike);
        self::assertSame($destination->getId(), $storedHike->getDestination()?->getId());
    }

    public function testVerifiedAdminCanCreateHierarchicalDestinationAsJson(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $token = $this->uniqueToken('destination');
        $areaName = 'Belvedere '.$token;
        $cityName = 'Ville '.$token;

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'type' => DestinationType::Area->value,
            'countryName' => 'France',
            'regionName' => 'Occitanie',
            'departmentName' => 'Pyrenees-Orientales',
            'departmentCode' => '66',
            'cityName' => $cityName,
            'areaName' => $areaName,
            'code' => '66088',
            'latitude' => '42,6201',
            'longitude' => '2,9712',
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $area = $this->entityManager()->getRepository(Destination::class)->findOneBy([
            'name' => $areaName,
            'type' => DestinationType::Area,
        ]);
        self::assertInstanceOf(Destination::class, $area);
        self::assertSame(42.6201, $area->getLatitude());
        self::assertSame(2.9712, $area->getLongitude());
        self::assertSame($cityName, $area->getParent()?->getName());
        self::assertSame('66088', $area->getParent()?->getCode());
        self::assertSame('Pyrenees-Orientales', $area->getParent()?->getParent()?->getName());
        self::assertSame('66', $area->getParent()?->getParent()?->getCode());
        self::assertSame('Occitanie', $area->getParent()?->getParent()?->getParent()?->getName());
        self::assertSame('76', $area->getParent()?->getParent()?->getParent()?->getCode());
        self::assertSame('France', $area->getParent()?->getParent()?->getParent()?->getParent()?->getName());
        self::assertSame('FR', $area->getParent()?->getParent()?->getParent()?->getParent()?->getCode());
        self::assertSame([
            'ok' => true,
            'destination' => [
                'id' => $area->getId(),
                'name' => $areaName,
                'type' => DestinationType::Area->value,
            ],
        ], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testVerifiedAdminCanCreateDestinationUnderExplicitParent(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $parent = $this->createDestination('Region parent '.$this->uniqueToken('destination'), DestinationType::Region);
        $areaName = 'Zone enfant '.$this->uniqueToken('destination');

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'parent' => $parent->getId(),
            'name' => $areaName,
            'type' => DestinationType::Area->value,
            'latitude' => '43.20',
            'longitude' => '2.34',
            'returnUrl' => '//example.test/not-allowed',
        ]);

        self::assertResponseRedirects('/admin');
        $area = $this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => $areaName]);
        self::assertInstanceOf(Destination::class, $area);
        self::assertSame($parent->getId(), $area->getParent()?->getId());
        self::assertSame(43.20, $area->getLatitude());
        self::assertSame(2.34, $area->getLongitude());
    }

    public function testInvalidCoordinatesDoNotOverwriteReusableDestinationWithZero(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $name = 'Destination coordonnées '.$this->uniqueToken('destination');
        $destination = $this->createDestination($name);
        $destination
            ->setLatitude(43.1234)
            ->setLongitude(2.5678);
        $this->persistAndFlush($destination);

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'name' => $name,
            'type' => DestinationType::Area->value,
            'latitude' => 'coordonnée-invalide',
            'longitude' => 'autre-valeur-invalide',
            'returnUrl' => '/admin',
        ]);

        self::assertResponseRedirects('/admin');
        $destination = $this->refresh($destination);
        self::assertSame(43.1234, $destination->getLatitude());
        self::assertSame(2.5678, $destination->getLongitude());
        self::assertNotSame(0.0, $destination->getLatitude());
        self::assertNotSame(0.0, $destination->getLongitude());
    }

    public function testVerifiedAdminCanAssociateQuickDestinationToCityVisit(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $destinationName = 'Destination visite '.$this->uniqueToken('destination');

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'contextType' => 'city_visit',
            'contextId' => $cityVisit->getId(),
            'targetType' => 'city_visit',
            'targetId' => $cityVisit->getId(),
            'name' => $destinationName,
            'type' => DestinationType::Area->value,
            'returnUrl' => sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $storedCityVisit = $this->refresh($cityVisit);
        self::assertInstanceOf(CityVisitDraft::class, $storedCityVisit);
        self::assertSame($destinationName, $storedCityVisit->getDestination()?->getName());
    }

    public function testVerifiedAdminCanAssociateQuickDestinationToPlace(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();
        $client->loginUser($this->createVerifiedAdmin());
        $destinationName = 'Destination lieu '.$this->uniqueToken('destination');

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'contextType' => 'place',
            'contextId' => $place->getId(),
            'targetType' => 'place',
            'targetId' => $place->getId(),
            'name' => $destinationName,
            'type' => DestinationType::Area->value,
            'returnUrl' => sprintf('/admin/studio/places/%d/edit', $place->getId()),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $storedPlace = $this->refresh($place);
        self::assertInstanceOf(Place::class, $storedPlace);
        self::assertSame($destinationName, $storedPlace->getDestination()?->getName());
    }

    public function testQuickHikeCreationReturnsJsonAndKeepsSlugUnique(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $commune = 'Slug rando '.$this->uniqueToken('commune');

        $this->postQuickHikeCreation($client, $commune, '16051');
        self::assertResponseIsSuccessful();
        $firstHike = $this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['title' => 'Randonnée à '.$commune]);
        self::assertInstanceOf(HikeDraft::class, $firstHike);
        $baseSlug = $firstHike->getSlug();
        self::assertNotNull($baseSlug);

        $this->postQuickHikeCreation($client, $commune, '16052');

        self::assertResponseIsSuccessful();
        $secondHike = $this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['slug' => $baseSlug.'-2']);
        self::assertInstanceOf(HikeDraft::class, $secondHike);
        self::assertSame([
            'ok' => true,
            'commune' => [
                'communeName' => $commune,
                'communeInseeCode' => '16052',
                'postalCode' => '16190',
                'departmentName' => 'Charente',
                'departmentCode' => '16',
                'regionName' => 'Nouvelle-Aquitaine',
                'country' => 'France',
                'communeCenterLatitude' => 45.3631,
                'communeCenterLongitude' => 0.0607,
                'latitude' => null,
                'longitude' => null,
                'gpsAccuracy' => null,
            ],
            'redirect' => sprintf('/admin/studio/hikes/%d/edit', $secondHike->getId()),
        ], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testQuickCityVisitCreationReturnsJsonAndKeepsSlugUnique(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $commune = 'Slug visite '.$this->uniqueToken('commune');

        $this->postQuickCityVisitCreation($client, $commune, '66138');
        self::assertResponseIsSuccessful();
        $firstVisit = $this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => 'Visite de ville à '.$commune]);
        self::assertInstanceOf(CityVisitDraft::class, $firstVisit);
        $baseSlug = $firstVisit->getSlug();
        self::assertNotNull($baseSlug);

        $this->postQuickCityVisitCreation($client, $commune, '66139');

        self::assertResponseIsSuccessful();
        $secondVisit = $this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['slug' => $baseSlug.'-2']);
        self::assertInstanceOf(CityVisitDraft::class, $secondVisit);
        self::assertSame([
            'ok' => true,
            'commune' => [
                'communeName' => $commune,
                'communeInseeCode' => '66139',
                'postalCode' => '66000',
                'departmentName' => 'Pyrenees-Orientales',
                'departmentCode' => '66',
                'regionName' => 'Occitanie',
                'country' => 'France',
                'communeCenterLatitude' => 42.6986,
                'communeCenterLongitude' => 2.8956,
                'latitude' => null,
                'longitude' => null,
                'gpsAccuracy' => null,
            ],
            'redirect' => sprintf('/admin/studio/city-visits/%d/edit', $secondVisit->getId()),
        ], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testQuickCityVisitCreationRejectsIncompleteGpsPair(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $commune = 'GPS incomplet '.$this->uniqueToken('commune');

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'contextType' => 'quick_city_visit',
            'targetType' => 'quick_city_visit',
            'type' => DestinationType::City->value,
            'name' => $commune,
            'countryName' => 'France',
            'regionName' => 'Occitanie',
            'departmentName' => 'Pyrenees-Orientales',
            'departmentCode' => '66',
            'cityName' => $commune,
            'code' => '66140',
            'postalCode' => '66000',
            'latitude' => '42.7023456',
            'returnUrl' => '/admin/quick?type=city_visit&mode=distance',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['ok' => false, 'message' => 'Le point GPS doit contenir une latitude et une longitude valides.'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
        self::assertNull($this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => 'Visite de ville à '.$commune]));
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

    public function testVerifiedAdminCanCreateDistanceCityVisitWithCommuneWithoutPrecisePoint(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/quick?type=city_visit&mode=distance');
        self::assertResponseIsSuccessful();

        $commune = 'Ville sans point '.$this->uniqueToken('commune');
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
            'code' => '66137',
            'postalCode' => '66100',
            'communeCenterLatitude' => '42.6886000',
            'communeCenterLongitude' => '2.8949000',
            'returnUrl' => '/admin/quick?type=city_visit&mode=distance',
        ]);

        $cityVisit = $this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => 'Visite de ville à '.$commune]);
        self::assertInstanceOf(CityVisitDraft::class, $cityVisit);
        self::assertNull($cityVisit->getDestination());
        self::assertInstanceOf(Destination::class, $cityVisit->getGeographicDestination());
        self::assertSame('66137', $cityVisit->getGeographicDestination()->getCode());
        self::assertSame('66137', $cityVisit->getDetectedCommuneCode());
        self::assertCount(0, $cityVisit->getPoints());
        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
    }

    public function testDistanceCityVisitWithGpsButMissingInseeCodeIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/quick?type=city_visit&mode=distance');
        self::assertResponseIsSuccessful();

        $commune = 'GPS sans insee '.$this->uniqueToken('commune');
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
            'latitude' => '42.7023456',
            'longitude' => '2.9012345',
            'returnUrl' => '/admin/quick?type=city_visit&mode=distance',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['ok' => false, 'message' => 'Sélectionnez une commune dans la liste avant de créer la visite.'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
        self::assertNull($this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => 'Visite de ville à '.$commune]));
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => $commune]));
    }

    public function testDistanceHikeWithGpsButMissingInseeCodeIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/quick?type=hike&mode=distance');
        self::assertResponseIsSuccessful();

        $commune = 'Rando GPS sans insee '.$this->uniqueToken('commune');
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
            'latitude' => '45.3631000',
            'longitude' => '0.0607000',
            'returnUrl' => '/admin/quick?type=hike&mode=distance',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['ok' => false, 'message' => 'Sélectionnez une commune dans la liste avant de créer la visite.'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
        self::assertNull($this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['title' => 'Randonnée à '.$commune]));
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => $commune]));
    }

    private function postQuickHikeCreation(KernelBrowser $client, string $commune, string $code): void
    {
        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'contextType' => 'quick_hike',
            'targetType' => 'quick_hike',
            'type' => DestinationType::City->value,
            'name' => $commune,
            'countryName' => 'France',
            'regionName' => 'Nouvelle-Aquitaine',
            'departmentName' => 'Charente',
            'departmentCode' => '16',
            'cityName' => $commune,
            'code' => $code,
            'postalCode' => '16190',
            'communeCenterLatitude' => '45.3631000',
            'communeCenterLongitude' => '0.0607000',
            'returnUrl' => '/admin/quick?type=hike&mode=distance',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function postQuickCityVisitCreation(KernelBrowser $client, string $commune, string $code): void
    {
        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->quickDestinationToken($client),
            'contextType' => 'quick_city_visit',
            'targetType' => 'quick_city_visit',
            'type' => DestinationType::City->value,
            'name' => $commune,
            'countryName' => 'France',
            'regionName' => 'Occitanie',
            'departmentName' => 'Pyrenees-Orientales',
            'departmentCode' => '66',
            'cityName' => $commune,
            'code' => $code,
            'postalCode' => '66000',
            'communeCenterLatitude' => '42.6986000',
            'communeCenterLongitude' => '2.8956000',
            'returnUrl' => '/admin/quick?type=city_visit&mode=distance',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function quickDestinationToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/quick?type=hike&mode=distance');
        self::assertResponseIsSuccessful();

        return $this->tokenFromFormAction($crawler, '/admin/studio/destinations/quick-create');
    }
}
