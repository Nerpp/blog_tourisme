<?php

namespace App\Tests\Functional;

use App\Entity\ArticleCityVisit;
use App\Entity\ArticleDestination;
use App\Entity\ArticleHike;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use Symfony\Component\DomCrawler\Crawler;

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

    public function testDestinationIndexDisplaysClickablePublicContentCounters(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/destinations');

        self::assertResponseIsSuccessful();
        $articleLink = $crawler->filter('[data-destination-summary-link="articles"]');
        $hikeLink = $crawler->filter('[data-destination-summary-link="hikes"]');
        $cityVisitLink = $crawler->filter('[data-destination-summary-link="city-visits"]');

        self::assertCount(1, $articleLink);
        self::assertCount(1, $hikeLink);
        self::assertCount(1, $cityVisitLink);
        self::assertSame('/articles', $articleLink->attr('href'));
        self::assertSame('/randonnees', $hikeLink->attr('href'));
        self::assertSame('/visites', $cityVisitLink->attr('href'));
        self::assertMatchesRegularExpression('/\d+/', $articleLink->filter('strong')->text());
        self::assertMatchesRegularExpression('/\d+/', $hikeLink->filter('strong')->text());
        self::assertMatchesRegularExpression('/\d+/', $cityVisitLink->filter('strong')->text());
    }

    public function testDestinationShowIsAccessible(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Massif fonctionnel', DestinationType::Area);

        $client->request('GET', sprintf('/destinations/%s', $destination->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $destination->getName());
    }

    public function testDestinationShowUsesRealHierarchyForBreadcrumbAndSearchNavigation(): void
    {
        $client = static::createClient();
        $country = $this->createDestination('France navigationnelle', DestinationType::Country);
        $region = $this->createDestination('Occitanie navigationnelle', DestinationType::Region, $country);
        $department = $this->createDestination('Pyrénées navigationnelles', DestinationType::Department, $region, '66-test');
        $city = $this->createDestination('Village navigationnel', DestinationType::City, $department, '66000-test');

        $crawler = $client->request('GET', sprintf('/destinations/%s?q=plage&type=article&ignored=1', $city->getSlug()));

        self::assertResponseIsSuccessful();
        $breadcrumb = $crawler->filter('.destination-detail-breadcrumb');
        self::assertSame([
            'Accueil',
            'Destinations',
            (string) $country->getName(),
            (string) $region->getName(),
            (string) $department->getName(),
            (string) $city->getName(),
        ], $this->navigationLabels($breadcrumb));
        self::assertSame('page', $breadcrumb->filter('[aria-current="page"]')->attr('aria-current'));
        self::assertSame('/', $breadcrumb->filter('a')->eq(0)->attr('href'));
        self::assertSame('/destinations', $breadcrumb->filter('a')->eq(1)->attr('href'));
        self::assertSame('/destinations/'.$country->getSlug(), $breadcrumb->filter('a')->eq(2)->attr('href'));
        self::assertSame('/destinations/'.$region->getSlug(), $breadcrumb->filter('a')->eq(3)->attr('href'));
        self::assertSame('/destinations/'.$department->getSlug(), $breadcrumb->filter('a')->eq(4)->attr('href'));

        $searchPath = $crawler->filter('.destination-detail-search-path');
        self::assertSame([
            'Toutes les destinations',
            (string) $country->getName(),
            (string) $region->getName(),
            (string) $department->getName(),
            (string) $city->getName(),
        ], $this->navigationLabels($searchPath));
        self::assertSame('page', $searchPath->filter('[aria-current="page"]')->attr('aria-current'));

        foreach ($searchPath->filter('a') as $index => $link) {
            $params = $this->queryParameters($link->getAttribute('href'));
            self::assertSame('plage', $params['q'] ?? null);
            if ($index === 0) {
                self::assertArrayNotHasKey('type', $params);
            } else {
                self::assertSame('article', $params['type'] ?? null);
            }
            self::assertArrayNotHasKey('ignored', $params);
        }
    }

    public function testDestinationShowKeepsDiscoverCountOnlyInRightSummaryCard(): void
    {
        $client = static::createClient();
        $country = $this->createDestination('France compteur', DestinationType::Country);
        $region = $this->createDestination('Occitanie compteur', DestinationType::Region, $country);
        $department = $this->createDestination('Département compteur', DestinationType::Department, $region, '98-test');
        $firstCity = $this->createDestination('Premier village compteur', DestinationType::City, $department, '98001-test');
        $secondCity = $this->createDestination('Second village compteur', DestinationType::City, $department, '98002-test');
        $this->createPublishedPlace($firstCity);
        $this->createPublishedPlace($secondCity);
        $this->entityManager()->clear();

        $crawler = $client->request('GET', sprintf('/destinations/%s', $department->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//*[normalize-space(.) = "2 lieux à découvrir"]'));
        self::assertSame('2 lieux à découvrir', trim($crawler->filter('.destination-detail-info [data-destination-discover-count]')->text()));
        self::assertCount(0, $crawler->filter('.destination-detail-badges'));
        self::assertSelectorTextContains('.destination-detail-info', (string) $region->getName());
    }

    public function testDestinationEditLinkIsVisibleOnlyToVerifiedAdmin(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Destination administrable', DestinationType::Area);

        $crawler = $client->request('GET', sprintf('/destinations/%s', $destination->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.destination-detail-admin-link'));

        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', sprintf('/destinations/%s', $destination->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSame(
            sprintf('/admin/destinations/%d/edit', $destination->getId()),
            $crawler->filter('.destination-detail-admin-link')->attr('href'),
        );
        self::assertSame('Modifier', trim($crawler->filter('.destination-detail-admin-link')->text()));
    }

    public function testDestinationShowHandlesHierarchyWithoutParent(): void
    {
        $client = static::createClient();
        $destination = $this->createDestination('Destination sans parent', DestinationType::Area);

        $crawler = $client->request('GET', sprintf('/destinations/%s', $destination->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSame(['Accueil', 'Destinations', (string) $destination->getName()], $this->navigationLabels($crawler->filter('.destination-detail-breadcrumb')));
        self::assertSame(['Toutes les destinations', (string) $destination->getName()], $this->navigationLabels($crawler->filter('.destination-detail-search-path')));
        self::assertCount(1, $crawler->filter('.destination-detail-breadcrumb [aria-current="page"]'));
        self::assertCount(1, $crawler->filter('.destination-detail-search-path [aria-current="page"]'));
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

    public function testPublicHikeUsesGeographicDestinationBeforeEditorialDestination(): void
    {
        $client = static::createClient();
        $country = $this->createDestination('France priority geo', DestinationType::Country);
        $region = $this->createDestination('Occitanie priority geo', DestinationType::Region, $country);
        $department = $this->createDestination('Pyrenees-Orientales priority geo', DestinationType::Department, $region, '66');
        $editorialCity = $this->createDestination('Llo priority editorial', DestinationType::City, $department, '66100');
        $geographicCity = $this->createDestination('Calce priority geographic', DestinationType::City, $department, '66030');
        $hike = $this->createPublishedHike($this->createUser(['ROLE_ADMIN', 'ROLE_USER']), $editorialCity);
        $hike
            ->setTitle('Randonnée priorité géographique')
            ->setGeographicDestination($geographicCity);
        $this->persistAndFlush($hike);

        $client->request('GET', sprintf('/destinations/%s', $geographicCity->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Randonnée priorité géographique');

        $client->request('GET', sprintf('/destinations/%s', $editorialCity->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Randonnée priorité géographique', $client->getResponse()->getContent() ?: '');
    }

    public function testPublicHikeFallsBackToEditorialDestinationWhenGeographicDestinationIsMissing(): void
    {
        $client = static::createClient();
        $country = $this->createDestination('France fallback geo', DestinationType::Country);
        $region = $this->createDestination('Occitanie fallback geo', DestinationType::Region, $country);
        $department = $this->createDestination('Pyrenees-Orientales fallback geo', DestinationType::Department, $region, '66');
        $editorialCity = $this->createDestination('Llo fallback editorial', DestinationType::City, $department, '66100');
        $hike = $this->createPublishedHike($this->createUser(['ROLE_ADMIN', 'ROLE_USER']), $editorialCity);
        $hike->setTitle('Randonnée fallback éditorial');
        $this->persistAndFlush($hike);

        $client->request('GET', sprintf('/destinations/%s', $editorialCity->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Randonnée fallback éditorial');
    }

    public function testDestinationShowDisplaysArticleContextsForDestinationHikeAndCityVisit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $country = $this->createDestination('France contextuelle', DestinationType::Country);
        $region = $this->createDestination('Occitanie contextuelle', DestinationType::Region, $country);
        $department = $this->createDestination('Aude contextuelle', DestinationType::Department, $region, '11');
        $city = $this->createDestination('Narbonne contextuelle', DestinationType::City, $department, '11262');

        $directArticle = $this->createArticle($admin);
        $directArticle->setTitle('Article directement lié à Narbonne');
        $directLink = (new ArticleDestination())
            ->setArticle($directArticle)
            ->setDestination($city);
        $directArticle->getDestinationLinks()->add($directLink);

        $hike = $this->createPublishedHike($admin, $city);
        $hike->setTitle('Sentier contexte destination');
        $hikeArticle = $this->createArticle($admin);
        $hikeArticle->setTitle('Article lié au sentier');
        $hikeLink = (new ArticleHike())
            ->setArticle($hikeArticle)
            ->setHikeDraft($hike)
            ->setRole('history');
        $hikeArticle->getHikeLinks()->add($hikeLink);

        $cityVisit = $this->createPublishedCityVisit($admin, $city);
        $cityVisit->setTitle('Visite contexte destination');
        $cityArticle = $this->createArticle($admin);
        $cityArticle->setTitle('Article lié à la visite');
        $cityVisitLink = (new ArticleCityVisit())
            ->setArticle($cityArticle)
            ->setCityVisitDraft($cityVisit)
            ->setRole('legend');
        $cityArticle->getCityVisitLinks()->add($cityVisitLink);

        $this->persistAndFlush($directArticle, $directLink, $hike, $hikeArticle, $hikeLink, $cityVisit, $cityArticle, $cityVisitLink);

        $client->request('GET', sprintf('/destinations/%s', $city->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Article directement lié à Narbonne');
        self::assertSelectorTextContains('body', 'Article lié au sentier');
        self::assertSelectorTextContains('body', 'Article lié à la visite');
        self::assertSelectorTextContains('body', 'Sentier contexte destination');
        self::assertSelectorTextContains('body', 'Visite contexte destination');
        self::assertSelectorTextContains('body', 'Histoire');
        self::assertSelectorTextContains('body', 'Légende');
    }

    public function testAreaDestinationSummarizesDepartmentContentAcrossDescendants(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $area = $this->createDestination('Arc mediterraneen fonctionnel', DestinationType::Area);
        $departmentA = $this->createDestination('Herault fonctionnel', DestinationType::Department, $area, '34');
        $departmentB = $this->createDestination('Gard fonctionnel', DestinationType::Department, $area, '30');
        $cityA = $this->createDestination('Sete fonctionnelle', DestinationType::City, $departmentA, '34301');
        $cityB = $this->createDestination('Nimes fonctionnelle', DestinationType::City, $departmentB, '30189');

        $article = $this->createArticle($admin);
        $article->setTitle('Article arc méditerranéen');
        $articleLink = (new ArticleDestination())
            ->setArticle($article)
            ->setDestination($cityA);
        $article->getDestinationLinks()->add($articleLink);

        $hike = $this->createPublishedHike($admin, $cityA);
        $hike->setTitle('Randonnée arc méditerranéen');
        $cityVisit = $this->createPublishedCityVisit($admin, $cityB);
        $cityVisit->setTitle('Visite arc méditerranéen');

        $this->persistAndFlush($article, $articleLink, $hike, $cityVisit);

        $client->request('GET', sprintf('/destinations/%s', $area->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Arc mediterraneen fonctionnel');
        self::assertSelectorTextContains('body', '1 article, 1 randonnée et 1 visite');
        self::assertSelectorTextContains('body', 'Article arc méditerranéen');
        self::assertSelectorTextContains('body', 'Randonnée arc méditerranéen');
        self::assertSelectorTextContains('body', 'Visite arc méditerranéen');
    }

    /** @return list<string> */
    private function navigationLabels(Crawler $navigation): array
    {
        return $navigation->filter('li')->each(
            static fn (Crawler $item): string => trim($item->text()),
        );
    }

    /** @return array<string, mixed> */
    private function queryParameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        return $params;
    }
}
