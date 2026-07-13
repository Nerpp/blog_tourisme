<?php

namespace App\Tests\Functional;

use App\Entity\ArticleCityVisit;
use App\Enum\CityVisitDraftStatus;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CityVisitControllerTest extends FunctionalTestCase
{
    public function testRelatedArticleLinkGoesDirectlyToTheFullArticleWithVisitContext(): void
    {
        $client = static::createClient();
        $cityVisit = $this->createPublishedCityVisit($this->createVerifiedAdmin());
        $article = $this->createArticle($this->createUser());
        $articleLink = (new ArticleCityVisit())->setArticle($article)->setCityVisitDraft($cityVisit);
        $cityVisit->getArticleLinks()->add($articleLink);
        $this->persistAndFlush($articleLink);

        $crawler = $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.related-article-card')->first();
        self::assertStringContainsString((string) $article->getTitle(), $card->text());
        self::assertStringContainsString((string) $article->getExcerpt(), $card->text());
        $link = $crawler->filter('.related-article-card > a.related-article-card__button');
        self::assertCount(1, $link);
        self::assertSame('Lire l’article', trim($link->text()));
        self::assertNull($link->attr('target'));
        self::assertSame(
            sprintf('/articles/%s?from=city_visit&source=%s', $article->getSlug(), $cityVisit->getSlug()),
            $link->attr('href'),
        );
        self::assertCount(0, $crawler->filter('.related-article-modal, .js-related-article-open'));
        self::assertSelectorTextNotContains('body', 'Lire la page complète');
    }

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
        self::assertStringNotContainsString('étape', $crawler->filter('.public-detail-hero')->text());
    }

    public function testCityVisitCoverUsesTheSameResponsiveWebpContract(): void
    {
        $client = static::createClient();
        $cityVisit = $this->createPublishedCityVisit($this->createVerifiedAdmin());
        $media = $this->createImageMedia('Couverture panoramique visite')
            ->setImageType(ImageType::Panorama)
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/visit-640.webp', 'fallback' => '/uploads/media/visit-640.jpg', 'width' => 640, 'height' => 360],
                'mobile' => ['webp' => '/uploads/media/visit-960.webp', 'fallback' => '/uploads/media/visit-960.jpg', 'width' => 960, 'height' => 540],
                'medium' => ['webp' => '/uploads/media/visit-1280.webp', 'fallback' => '/uploads/media/visit-1280.jpg', 'width' => 1280, 'height' => 720],
                'large' => ['webp' => '/uploads/media/visit-2560.webp', 'fallback' => '/uploads/media/visit-2560.jpg', 'width' => 2560, 'height' => 1440],
            ]);
        $this->persistAndFlush($media);
        $this->linkCityVisitMedia($cityVisit, $media, MediaRole::Cover);

        $crawler = $client->request('GET', sprintf('/visites-de-ville/%s', $cityVisit->getSlug()));

        self::assertResponseIsSuccessful();
        $cover = $crawler->filter('.public-detail-cover--media');
        self::assertSame(1, $cover->count());
        self::assertSame('/uploads/media/visit-640.webp 640w, /uploads/media/visit-960.webp 960w, /uploads/media/visit-1280.webp 1280w', $cover->filter('source[type="image/webp"]')->attr('srcset'));
        $image = $cover->filter('img.public-detail-cover__image');
        self::assertSame('/uploads/media/visit-1280.jpg', $image->attr('src'));
        self::assertSame('eager', $image->attr('loading'));
        self::assertSame('high', $image->attr('fetchpriority'));
        self::assertSame('1280', $image->attr('width'));
        self::assertSame('720', $image->attr('height'));
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
