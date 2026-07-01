<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;

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

    public function testQuickCityVisitPointRejectsMissingCoordinates(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-gps-form][data-high-precision-gps][action$="/point"]');
        self::assertSelectorTextContains(
            'form[data-gps-form][data-high-precision-gps][action$="/point"] button[type="submit"]',
            'Ajouter ma position actuelle',
        );

        $client->request('POST', sprintf('/admin/quick-city-visit/%d/point', $cityVisit->getId()), [
            'quick_city_visit_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_city_visit_point[_token]"]'),
                'type' => CityVisitPointType::Start->value,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-alert', 'La position GPS est obligatoire.');
        self::assertSame(0, $this->entityManager()->getRepository(CityVisitPoint::class)->count(['cityVisitDraft' => $cityVisit]));
    }

    public function testQuickCityVisitPointRejectsInvalidAccuracy(): void
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
                'latitude' => '43.6001',
                'longitude' => '3.8801',
                'accuracy' => 'large',
                'type' => CityVisitPointType::Start->value,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-alert', 'La précision GPS est invalide.');
        self::assertSame(0, $this->entityManager()->getRepository(CityVisitPoint::class)->count(['cityVisitDraft' => $cityVisit]));
    }

    public function testQuickCityVisitPointRejectsStructuredFieldsWithoutCreatingLocation(): void
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
                'latitude' => ['43.60'],
                'longitude' => ['3.88'],
                'accuracy' => ['5'],
                'type' => ['monument'],
                'titlePoint' => ['Titre structuré'],
                'note' => ['Note structurée'],
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

    public function testQuickCityVisitPointCreatesStartPointWithCoordinates(): void
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
                'latitude' => '43,6001234',
                'longitude' => '3.8805678',
                'accuracy' => '6',
                'type' => CityVisitPointType::Start->value,
                'titlePoint' => 'Départ visite',
                'note' => 'Départ relevé automatiquement.',
            ],
        ]);

        self::assertResponseRedirects(sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));

        $points = $this->entityManager()->getRepository(CityVisitPoint::class)->findBy(['cityVisitDraft' => $cityVisit], ['position' => 'ASC']);
        self::assertCount(1, $points);
        self::assertSame(CityVisitPointType::Start, $points[0]->getType());
        self::assertSame('Départ visite', $points[0]->getTitle());
        self::assertSame('Départ relevé automatiquement.', $points[0]->getNote());
        self::assertEqualsWithDelta(43.6001234, $points[0]->getLatitude(), 0.0000001);
        self::assertEqualsWithDelta(3.8805678, $points[0]->getLongitude(), 0.0000001);
        self::assertEqualsWithDelta(6.0, $points[0]->getAccuracy(), 0.0000001);
        self::assertSame(1, $points[0]->getPosition());
        $stored = $this->refresh($cityVisit);
        self::assertInstanceOf(CityVisitDraft::class, $stored);
        self::assertNull($stored->getGoogleMapsUrl());
    }

    public function testQuickCityVisitPointCreatesInterestPointAfterStart(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $startPoint = $this->createCityVisitPoint($cityVisit, 43.600000, 3.880000, 1);
        $startPoint->setType(CityVisitPointType::Start);
        $this->persistAndFlush($startPoint);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-gps-form][data-high-precision-gps][action$="/point"]');
        self::assertSelectorTextContains(
            'form[data-gps-form][data-high-precision-gps][action$="/point"] button[type="submit"]',
            'Ajouter ma position actuelle',
        );

        $client->request('POST', sprintf('/admin/quick-city-visit/%d/point', $cityVisit->getId()), [
            'quick_city_visit_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_city_visit_point[_token]"]'),
                'latitude' => '43.610000',
                'longitude' => '3.890000',
                'accuracy' => '12',
                'type' => CityVisitPointType::Viewpoint->value,
                'titlePoint' => 'Belvédère visite',
                'note' => 'Vue dégagée.',
            ],
        ]);

        self::assertResponseRedirects(sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));

        $points = $this->entityManager()->getRepository(CityVisitPoint::class)->findBy(['cityVisitDraft' => $cityVisit], ['position' => 'ASC']);
        self::assertCount(2, $points);
        self::assertSame(CityVisitPointType::Start, $points[0]->getType());
        self::assertSame(CityVisitPointType::Viewpoint, $points[1]->getType());
        self::assertSame('Belvédère visite', $points[1]->getTitle());
        self::assertSame('Vue dégagée.', $points[1]->getNote());
        self::assertEqualsWithDelta(43.61, $points[1]->getLatitude(), 0.0000001);
        self::assertEqualsWithDelta(3.89, $points[1]->getLongitude(), 0.0000001);
        self::assertEqualsWithDelta(12.0, $points[1]->getAccuracy(), 0.0000001);
        self::assertSame(2, $points[1]->getPosition());
        $stored = $this->refresh($cityVisit);
        self::assertInstanceOf(CityVisitDraft::class, $stored);
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=43.600000,3.880000&destination=43.610000,3.890000&travelmode=walking',
            $stored->getGoogleMapsUrl(),
        );
    }

    public function testQuickCityVisitPointRejectsFinishedDraftAsJson(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $cityVisit->setFinishedAt(new \DateTimeImmutable('-5 minutes'));
        $this->persistAndFlush($cityVisit);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/quick-city-visit/%d/point', $cityVisit->getId()), [
            'quick_city_visit_point' => [
                '_token' => $this->csrfTokenForClient($client, sprintf('quick_city_visit_point_%d', $cityVisit->getId())),
                'latitude' => '43.60',
                'longitude' => '3.88',
                'type' => CityVisitPointType::Start->value,
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([
            'ok' => false,
            'message' => 'Cette sortie terrain est terminée. Modifiez la visite depuis le studio.',
        ], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->entityManager()->getRepository(CityVisitPoint::class)->count(['cityVisitDraft' => $cityVisit]));
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
