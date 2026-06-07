<?php

namespace App\Tests\Functional;

use App\Entity\PrevisionDestination;
use App\Enum\CityVisitDraftStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;

final class PrevisionDestinationControllerTest extends FunctionalTestCase
{
    public function testVerifiedAdminCanAccessPrevisionDestinationIndex(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/previsions/destinations');

        self::assertResponseIsSuccessful();
        self::assertSame('Prévision destination', $crawler->filter('h1')->text());
        self::assertStringContainsString('Sauvegardez des idées de lieux, communes ou positions GPS à explorer plus tard.', $crawler->text());
        self::assertStringNotContainsString('Relever ma position GPS', $crawler->text());
    }

    public function testRegularUserCannotAccessPrevisionDestinationIndex(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/previsions/destinations');

        self::assertResponseRedirects('/');
    }

    public function testPrevisionDestinationIndexDoesNotDisplayStudioDrafts(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $destination = $this->createDestination('Destination studio '.$this->uniqueToken('destination'), DestinationType::Area);
        $hike = $this->createHikeDraft($admin, $destination)
            ->setTitle('Randonnée Studio exclue '.$this->uniqueToken('hike'))
            ->setGeographicDestination($destination)
            ->setStatus(HikeDraftStatus::Finished);
        $cityVisit = $this->createCityVisitDraft($admin, $destination)
            ->setTitle('Visite Studio exclue '.$this->uniqueToken('city'))
            ->setGeographicDestination($destination)
            ->setStatus(CityVisitDraftStatus::Finished);
        $this->persistAndFlush($hike, $cityVisit);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/previsions/destinations');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $hike->getTitle(), $crawler->text());
        self::assertStringNotContainsString((string) $cityVisit->getTitle(), $crawler->text());
        self::assertStringNotContainsString('Ouvrir dans le Studio', $crawler->text());
    }

    public function testVerifiedAdminCanCreateAndSeeManualPrevisionDestination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $title = 'Cascade à vérifier près de Céret '.$this->uniqueToken('prevision');
        $crawler = $client->request('GET', '/admin/previsions/destinations/new');
        self::assertResponseIsSuccessful();
        self::assertSame('Ajouter une destination', $crawler->filter('h1')->text());
        $listLink = $crawler->filter('a[href="/admin/previsions/destinations"]');
        self::assertGreaterThan(0, $listLink->count());
        self::assertStringContainsString('Voir les destinations enregistrées', $listLink->text());

        $client->request('POST', '/admin/previsions/destinations/new', $this->formData($crawler, [
            'title' => $title,
            'status' => PrevisionDestination::STATUS_TO_CHECK,
            'source' => PrevisionDestination::SOURCE_MANUAL,
            'notes' => 'Accès, parking et drone à vérifier.',
            'priority' => PrevisionDestination::PRIORITY_HIGH,
            'plannedPeriod' => 'après pluie',
        ]));

        self::assertResponseRedirects('/admin/previsions/destinations');
        $previsionDestination = $this->entityManager()->getRepository(PrevisionDestination::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(PrevisionDestination::class, $previsionDestination);

        $crawler = $client->request('GET', '/admin/previsions/destinations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($title, $crawler->text());
        self::assertStringContainsString('À vérifier', $crawler->text());
        self::assertStringContainsString('Haute', $crawler->text());
    }

    public function testVerifiedAdminCanEditPrevisionDestinationAndSaveGpsAndCommuneFields(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $previsionDestination = (new PrevisionDestination())
            ->setTitle('Lieu à modifier '.$this->uniqueToken('prevision'))
            ->setStatus(PrevisionDestination::STATUS_IDEA)
            ->setSource(PrevisionDestination::SOURCE_MANUAL);
        $this->persistAndFlush($previsionDestination);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/previsions/destinations/%d/edit', $previsionDestination->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/previsions/destinations/%d/edit', $previsionDestination->getId()), $this->formData($crawler, [
            'title' => 'Belvédère GPS '.$this->uniqueToken('prevision'),
            'status' => PrevisionDestination::STATUS_TO_VISIT,
            'source' => PrevisionDestination::SOURCE_GPS,
            'country' => 'France',
            'region' => 'Occitanie',
            'department' => 'Pyrénées-Orientales',
            'commune' => 'Céret',
            'inseeCode' => '66049',
            'postalCode' => '66400',
            'latitude' => '42.4851200',
            'longitude' => '2.7483400',
            'gpsAccuracy' => '12',
            'priority' => PrevisionDestination::PRIORITY_MEDIUM,
            'plannedPeriod' => 'printemps',
            'notes' => 'Tester accès matin.',
        ]));

        self::assertResponseRedirects('/admin/previsions/destinations');
        $stored = $this->refresh($previsionDestination);
        self::assertInstanceOf(PrevisionDestination::class, $stored);
        self::assertSame(PrevisionDestination::STATUS_TO_VISIT, $stored->getStatus());
        self::assertSame(PrevisionDestination::SOURCE_GPS, $stored->getSource());
        self::assertSame('Céret', $stored->getCommune());
        self::assertSame('Pyrénées-Orientales', $stored->getDepartment());
        self::assertSame('Occitanie', $stored->getRegion());
        self::assertSame('66049', $stored->getInseeCode());
        self::assertSame('66400', $stored->getPostalCode());
        self::assertSame(42.4851200, $stored->getLatitude());
        self::assertSame(2.7483400, $stored->getLongitude());
        self::assertSame(12.0, $stored->getGpsAccuracy());
    }

    public function testVerifiedAdminCanDeletePrevisionDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $previsionDestination = (new PrevisionDestination())
            ->setTitle('Destination à supprimer '.$this->uniqueToken('prevision'))
            ->setStatus(PrevisionDestination::STATUS_IDEA);
        $this->persistAndFlush($previsionDestination);
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/previsions/destinations');
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/previsions/destinations/%d/delete', $previsionDestination->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/previsions/destinations/%d/delete', $previsionDestination->getId())),
        ]);

        self::assertResponseRedirects('/admin/previsions/destinations');
        self::assertNull($this->entityManager()->find(PrevisionDestination::class, $previsionDestination->getId()));
    }

    public function testAdminMenuContainsPrevisionDestinationEntry(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/previsions/destinations/new');

        self::assertResponseIsSuccessful();
        self::assertSame('Ajouter une destination', $crawler->filter('h1')->text());
        self::assertGreaterThan(0, $crawler->filter('nav.admin-nav a[href="/admin/previsions/destinations/new"]')->count());
        self::assertStringContainsString('Prévision', $crawler->filter('nav.admin-nav')->text());
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, array<string, string>>
     */
    private function formData(\Symfony\Component\DomCrawler\Crawler $crawler, array $values): array
    {
        return [
            'prevision_destination' => array_merge([
                '_token' => $this->inputValue($crawler, 'input[name="prevision_destination[_token]"]'),
                'title' => '',
                'status' => PrevisionDestination::STATUS_IDEA,
                'source' => '',
                'notes' => '',
                'country' => '',
                'region' => '',
                'department' => '',
                'commune' => '',
                'inseeCode' => '',
                'postalCode' => '',
                'latitude' => '',
                'longitude' => '',
                'gpsAccuracy' => '',
                'priority' => '',
                'plannedPeriod' => '',
            ], $values),
        ];
    }
}
