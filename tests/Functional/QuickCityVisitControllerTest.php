<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Enum\CityVisitDraftStatus;

final class QuickCityVisitControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromQuickCityVisit(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/quick-city-visit');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromQuickCityVisit(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/quick-city-visit');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminIndexRedirectsToQuickCityVisitChoice(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/quick-city-visit?mode=distance');

        self::assertResponseRedirects('/admin/quick?type=city_visit&mode=distance');
    }

    public function testVerifiedAdminCanStartCityVisitWithoutDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $title = 'Terrain visite fonctionnelle '.$this->uniqueToken('city');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/quick?type=city_visit&mode=terrain');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/quick-city-visit/start', [
            '_token' => $this->tokenFromFormAction($crawler, '/admin/quick-city-visit/start'),
            'title' => $title,
            'detectedCommuneName' => 'Commune partielle ignorée',
            'detectedCommuneCode' => '',
        ]);

        $cityVisit = $this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(CityVisitDraft::class, $cityVisit);
        self::assertNull($cityVisit->getDestination());
        self::assertNull($cityVisit->getGeographicDestination());
        self::assertNull($cityVisit->getDetectedCommuneName());
        self::assertNull($cityVisit->getDetectedCommuneCode());
        self::assertCount(0, $cityVisit->getPoints());
        self::assertSame($admin->getId(), $cityVisit->getCreatedBy()?->getId());
        self::assertResponseRedirects(sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));

        $session = $client->getRequest()->getSession();
        self::assertFalse($session->has('quick_hike_destination_id'));
        self::assertFalse($session->has('quick_city_visit_destination_id'));
        self::assertFalse($session->has('quick_hike_commune'));
    }

    public function testQuickCityVisitStartRequiresCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/quick-city-visit/start', [
            '_token' => 'bad-token',
            'title' => 'Quick city csrf invalide',
        ]);

        self::assertResponseRedirects('/admin/quick-city-visit');
        self::assertNull($this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => 'Quick city csrf invalide']));
    }

    public function testQuickCityVisitPointRejectsInvalidCoordinates(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/quick-city-visit/%d/point', $cityVisit->getId()), [
            'quick_city_visit_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_city_visit_point[_token]"]'),
                'latitude' => '43.60',
                'longitude' => '200',
                'type' => 'interest',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(CityVisitPoint::class)->count(['cityVisitDraft' => $cityVisit]));
        $stored = $this->refresh($cityVisit);
        self::assertInstanceOf(CityVisitDraft::class, $stored);
        self::assertNull($stored->getGeographicDestination());
        self::assertNull($stored->getDetectedCommuneName());
        self::assertNull($stored->getGoogleMapsUrl());
    }

    public function testQuickCityVisitFinishRequiresCsrf(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/quick-city-visit/%d/finish', $cityVisit->getId()), [
            '_token' => 'bad-token',
        ]);

        self::assertResponseRedirects(sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));
        $stored = $this->refresh($cityVisit);
        self::assertInstanceOf(CityVisitDraft::class, $stored);
        self::assertNull($stored->getFinishedAt());
    }

    public function testVerifiedAdminCanFinishCityVisit(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $this->createCityVisitPoint($cityVisit, 43.600000, 3.880000, 1);
        $this->createCityVisitPoint($cityVisit, 43.610000, 3.890000, 2);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/quick-city-visit/%d/finish', $cityVisit->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/quick-city-visit/%d/finish', $cityVisit->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $stored = $this->refresh($cityVisit);
        self::assertInstanceOf(CityVisitDraft::class, $stored);
        self::assertSame(CityVisitDraftStatus::Draft, $stored->getStatus());
        self::assertNotNull($stored->getFinishedAt());
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=43.600000,3.880000&destination=43.610000,3.890000&travelmode=walking',
            $stored->getGoogleMapsUrl(),
        );
    }
}
