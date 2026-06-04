<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraft;

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
        ]);

        $cityVisit = $this->entityManager()->getRepository(CityVisitDraft::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(CityVisitDraft::class, $cityVisit);
        self::assertNull($cityVisit->getDestination());
        self::assertSame($admin->getId(), $cityVisit->getCreatedBy()?->getId());
        self::assertResponseRedirects(sprintf('/admin/quick-city-visit/%d', $cityVisit->getId()));
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
        $cityVisit = $this->createCityVisitDraft($this->createVerifiedAdmin());
        $client->loginUser($this->createVerifiedAdmin());
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
    }
}
