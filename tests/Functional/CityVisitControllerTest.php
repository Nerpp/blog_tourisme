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

        $crawler = $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $cityVisit->getTitle());
        $cover = $crawler->filter('.public-detail-cover')->first();
        self::assertSame('', $cover->attr('aria-label') ?? '');
        self::assertSame('', $cover->attr('role') ?? '');
    }

    public function testCityVisitIndexListsOnlyPublicVisits(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $published = $this->createPublishedCityVisit($admin);
        $draft = $this->createCityVisitDraft($admin);
        $draft->setTitle('Visite brouillon invisible '.$this->uniqueToken('visit'));
        $this->persistAndFlush($draft);

        $client->request('GET', '/visites');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $published->getTitle());
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testCityVisitIndexSearchFiltersByTitleAndKeepsQuery(): void
    {
        $client = static::createClient();
        $token = $this->uniqueToken('perpignan');
        $admin = $this->createVerifiedAdmin();
        $matching = $this->createPublishedCityVisit($admin);
        $matching->setTitle('Visite Perpignan publique '.$token);
        $draft = $this->createCityVisitDraft($admin);
        $draft->setTitle('Visite Perpignan brouillon '.$token);
        $unrelated = $this->createPublishedCityVisit($admin);
        $unrelated->setTitle('Visite hors recherche '.$this->uniqueToken('other'));
        $this->persistAndFlush($matching, $draft, $unrelated);

        $client->request('GET', '/visites?q='.rawurlencode(strtoupper($token)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $matching->getTitle());
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString((string) $unrelated->getTitle(), (string) $client->getResponse()->getContent());
        self::assertSelectorExists('input[name="q"][value="'.strtoupper($token).'"]');
    }

    public function testCityVisitIndexSearchDisplaysEmptyState(): void
    {
        $client = static::createClient();

        $client->request('GET', '/visites?q='.rawurlencode($this->uniqueToken('no-result')));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune visite ne correspond à cette recherche.');
    }

    public function testCityVisitSuggestionsRequireTwoCharactersAndReturnLimitedPublicResults(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $token = $this->uniqueToken('suggest-visit');
        for ($index = 0; $index < 10; ++$index) {
            $cityVisit = $this->createPublishedCityVisit($admin);
            $cityVisit->setTitle(sprintf('Suggestion visite %s %02d', $token, $index));
            $this->persistAndFlush($cityVisit);
        }
        $draft = $this->createCityVisitDraft($admin);
        $draft->setTitle('Suggestion visite brouillon '.$token);
        $this->persistAndFlush($draft);

        $client->request('GET', '/visites/suggestions?q=a');
        self::assertResponseIsSuccessful();
        self::assertSame(['suggestions' => []], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));

        $client->request('GET', '/visites/suggestions?q='.rawurlencode($token));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['suggestions'] ?? null);
        self::assertLessThanOrEqual(8, count($payload['suggestions']));
        self::assertNotSame([], $payload['suggestions']);
        self::assertSame('Visite', $payload['suggestions'][0]['type']);
        self::assertStringStartsWith('/visites-de-ville/', (string) $payload['suggestions'][0]['url']);
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
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
