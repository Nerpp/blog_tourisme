<?php

namespace App\Tests\Functional;

use App\Entity\ArticleCityVisit;
use App\Entity\ArticleDestination;
use App\Entity\ArticleHike;
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
}
