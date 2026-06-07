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
        self::assertSame('Prévisions destination', $crawler->filter('h1')->text());
        self::assertStringContainsString('Retrouvez vos idées de randonnées, visites et lieux à explorer plus tard.', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('a[href="/admin/previsions/destinations/new"]')->count());
        self::assertStringContainsString('Type', $crawler->filter('table thead')->text());
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

    public function testAutocompleteRouteIsProtectedForRegularUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/previsions/destinations/autocomplete?q=Ola');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanCreateAndSeeManualPrevisionDestination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $type = 'Randonnée';
        $crawler = $client->request('GET', '/admin/previsions/destinations/new');
        self::assertResponseIsSuccessful();
        self::assertSame('Ajouter une destination', $crawler->filter('h1')->text());
        $listLink = $crawler->filter('main.admin-main a[href="/admin/previsions/destinations"]');
        self::assertGreaterThan(0, $listLink->count());
        self::assertStringContainsString('Retour à l’index', $listLink->text());
        self::assertStringContainsString('Type de sortie prévue', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('select[name="prevision_destination[title]"] option[value="Randonnée"]')->count());
        self::assertGreaterThan(0, $crawler->filter('select[name="prevision_destination[title]"] option[value="Visite"]')->count());

        $client->request('POST', '/admin/previsions/destinations/new', $this->formData($crawler, [
            'title' => $type,
            'status' => PrevisionDestination::STATUS_TO_CHECK,
            'source' => PrevisionDestination::SOURCE_MANUAL,
            'notes' => 'Accès, parking et drone à vérifier.',
            'commune' => 'Céret',
            'priority' => PrevisionDestination::PRIORITY_HIGH,
            'plannedPeriod' => 'après pluie',
        ]));

        self::assertResponseRedirects('/admin/previsions/destinations');
        $previsionDestination = $this->entityManager()->getRepository(PrevisionDestination::class)->findOneBy(['title' => $type, 'commune' => 'Céret']);
        self::assertInstanceOf(PrevisionDestination::class, $previsionDestination);

        $crawler = $client->request('GET', '/admin/previsions/destinations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($type, $crawler->text());
        self::assertStringContainsString('À vérifier', $crawler->text());
        self::assertStringContainsString('Haute', $crawler->text());
        self::assertStringContainsString('Accès, parking et drone à vérifier.', $crawler->text());
    }

    public function testVerifiedAdminCanCreateVisitPrevisionDestination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $commune = 'Visiteville '.$this->uniqueToken('prevision');
        $crawler = $client->request('GET', '/admin/previsions/destinations/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/previsions/destinations/new', $this->formData($crawler, [
            'title' => 'Visite',
            'status' => PrevisionDestination::STATUS_IDEA,
            'source' => PrevisionDestination::SOURCE_SEARCH,
            'commune' => $commune,
        ]));

        self::assertResponseRedirects('/admin/previsions/destinations');
        $previsionDestination = $this->entityManager()->getRepository(PrevisionDestination::class)->findOneBy(['title' => 'Visite', 'commune' => $commune]);
        self::assertInstanceOf(PrevisionDestination::class, $previsionDestination);
    }

    public function testVerifiedAdminCanEditPrevisionDestinationAndSaveGpsAndCommuneFields(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $previsionDestination = (new PrevisionDestination())
            ->setTitle('Randonnée')
            ->setStatus(PrevisionDestination::STATUS_IDEA)
            ->setSource(PrevisionDestination::SOURCE_MANUAL);
        $this->persistAndFlush($previsionDestination);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/previsions/destinations/%d/edit', $previsionDestination->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/previsions/destinations/%d/edit', $previsionDestination->getId()), $this->formData($crawler, [
            'title' => 'Visite',
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
        self::assertSame('Visite', $stored->getTitle());
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

    public function testPrevisionDestinationIndexDisplaysNotesFallbackAndMapsLink(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $previsionDestination = (new PrevisionDestination())
            ->setTitle('Randonnée')
            ->setStatus(PrevisionDestination::STATUS_IDEA)
            ->setSource(PrevisionDestination::SOURCE_GPS)
            ->setCommune('Olargues')
            ->setDepartment('Hérault')
            ->setRegion('Occitanie')
            ->setLatitude(43.538000)
            ->setLongitude(2.917300);
        $emptyNotes = (new PrevisionDestination())
            ->setTitle('Visite')
            ->setStatus(PrevisionDestination::STATUS_IDEA)
            ->setCommune('Commune sans note '.$this->uniqueToken('prevision'));
        $this->persistAndFlush($previsionDestination, $emptyNotes);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/previsions/destinations');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucune note renseignée.', $crawler->text());
        $mapsLink = $crawler->filter('a[href*="google.com/maps"][href*="43.5380000%2C2.9173000"]');
        self::assertGreaterThan(0, $mapsLink->count());
        self::assertSame('_blank', $mapsLink->first()->attr('target'));
        self::assertSame('noopener noreferrer', $mapsLink->first()->attr('rel'));
    }

    public function testPrevisionDestinationIndexSearchFiltersByQueryString(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $matching = (new PrevisionDestination())
            ->setTitle('Randonnée')
            ->setStatus(PrevisionDestination::STATUS_IDEA)
            ->setCommune('Olargues')
            ->setDepartment('Hérault')
            ->setRegion('Occitanie')
            ->setNotes('À vérifier après pluie pour drone.')
            ->setPlannedPeriod('été');
        $other = (new PrevisionDestination())
            ->setTitle('Visite')
            ->setStatus(PrevisionDestination::STATUS_IDEA)
            ->setCommune('Ville hors recherche '.$this->uniqueToken('prevision'));
        $this->persistAndFlush($matching, $other);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/previsions/destinations?q=Olargues');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Olargues', $crawler->text());
        self::assertStringContainsString('À vérifier après pluie pour drone.', $crawler->text());
        self::assertStringNotContainsString((string) $other->getCommune(), $crawler->text());
        $resetLink = $crawler->filter('main.admin-main a[href="/admin/previsions/destinations"]');
        self::assertGreaterThan(0, $resetLink->count());
        self::assertStringContainsString('Réinitialiser', $resetLink->text());
    }

    public function testPrevisionDestinationIndexSearchDisplaysEmptyMessage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/previsions/destinations?q=aucun-resultat-'.$this->uniqueToken('prevision'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucune destination prévue ne correspond à cette recherche.', $crawler->text());
    }

    public function testPrevisionDestinationAutocompleteReturnsLimitedJsonSuggestions(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $token = 'Autocomplete '.$this->uniqueToken('prevision');
        for ($index = 0; $index < 11; ++$index) {
            $this->entityManager()->persist((new PrevisionDestination())
                ->setTitle('Randonnée')
                ->setStatus(PrevisionDestination::STATUS_IDEA)
                ->setCommune($token.' '.$index)
                ->setDepartment('Hérault')
                ->setRegion('Occitanie'));
        }
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/previsions/destinations/autocomplete?q='.rawurlencode($token));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertLessThanOrEqual(8, count($payload));
        self::assertNotEmpty($payload);
        self::assertStringContainsString('Randonnée', (string) $payload[0]['label']);
        self::assertStringContainsString('Hérault / Occitanie', (string) $payload[0]['detail']);
    }

    public function testPrevisionDestinationAutocompleteReturnsEmptyListForShortQuery(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/previsions/destinations/autocomplete?q=O');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('[]', (string) $client->getResponse()->getContent());
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
        self::assertGreaterThan(0, $crawler->filter('nav.admin-nav a[href="/admin/previsions/destinations"]')->count());
        self::assertGreaterThan(0, $crawler->filter('nav.admin-nav a[href="/admin/previsions/destinations/new"]')->count());
        self::assertStringContainsString('Index', $crawler->filter('nav.admin-nav')->text());
        self::assertStringContainsString('Ajouter une destination', $crawler->filter('nav.admin-nav')->text());
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
