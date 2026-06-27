<?php

namespace App\Tests\Functional;

use App\Entity\ArticleMedia;
use App\Enum\ContentStatus;
use App\Enum\MediaRole;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ArticleControllerTest extends FunctionalTestCase
{
    public function testArticleIndexListsPublishedArticles(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());

        $client->request('GET', '/articles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Articles');
        self::assertSelectorTextContains('body', (string) $article->getTitle());
    }

    public function testArticleIndexDoesNotListDraftArticles(): void
    {
        $client = static::createClient();
        $published = $this->createArticle($this->createUser());
        $draft = $this->createArticle($this->createUser());
        $draft
            ->setTitle('Article brouillon invisible '.$this->uniqueToken('article'))
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $this->persistAndFlush($draft);

        $client->request('GET', '/articles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $published->getTitle());
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testArticleIndexSearchFiltersCaseInsensitively(): void
    {
        $client = static::createClient();
        $token = $this->uniqueToken('roca');
        $matching = $this->createArticle($this->createUser());
        $matching->setTitle('Guide Roca public '.$token);
        $hiddenDraft = $this->createArticle($this->createUser());
        $hiddenDraft
            ->setTitle('Guide Roca brouillon '.$token)
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $unrelated = $this->createArticle($this->createUser());
        $unrelated->setTitle('Article hors recherche '.$this->uniqueToken('other'));
        $this->persistAndFlush($matching, $hiddenDraft, $unrelated);

        $client->request('GET', '/articles?q='.rawurlencode(strtoupper($token)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $matching->getTitle());
        self::assertStringNotContainsString((string) $hiddenDraft->getTitle(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString((string) $unrelated->getTitle(), (string) $client->getResponse()->getContent());
        self::assertSelectorExists('input[name="q"][value="'.strtoupper($token).'"]');
    }

    public function testArticleIndexSearchDisplaysEmptyState(): void
    {
        $client = static::createClient();

        $client->request('GET', '/articles?q='.rawurlencode($this->uniqueToken('no-result')));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun article ne correspond à cette recherche.');
    }

    public function testArticleSuggestionsRequireTwoCharactersAndReturnLimitedPublicResults(): void
    {
        $client = static::createClient();
        $token = $this->uniqueToken('suggest');
        for ($index = 0; $index < 10; ++$index) {
            $article = $this->createArticle($this->createUser());
            $article->setTitle(sprintf('Suggestion article %s %02d', $token, $index));
            $this->persistAndFlush($article);
        }
        $draft = $this->createArticle($this->createUser());
        $draft
            ->setTitle('Suggestion article brouillon '.$token)
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $this->persistAndFlush($draft);

        $client->request('GET', '/articles/suggestions?q=a');
        self::assertResponseIsSuccessful();
        self::assertSame(['suggestions' => []], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));

        $client->request('GET', '/articles/suggestions?q='.rawurlencode($token));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['suggestions'] ?? null);
        self::assertLessThanOrEqual(8, count($payload['suggestions']));
        self::assertNotSame([], $payload['suggestions']);
        self::assertArrayHasKey('title', $payload['suggestions'][0]);
        self::assertArrayHasKey('url', $payload['suggestions'][0]);
        self::assertArrayHasKey('type', $payload['suggestions'][0]);
        self::assertStringStartsWith('/articles/', (string) $payload['suggestions'][0]['url']);
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testPublishedArticleIsAccessibleWithPopularCommentSort(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $comment = $this->createComment($this->createUser(), $article);

        $crawler = $client->request('GET', sprintf('/articles/%s?comments_sort=popular', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $article->getTitle());
        self::assertSelectorTextContains('body', (string) $comment->getContent());
        $cover = $crawler->filter('.public-detail-cover')->first();
        self::assertSame('', $cover->attr('aria-label') ?? '');
        self::assertSame('', $cover->attr('role') ?? '');
    }

    public function testPublishedArticleCoverUsesMediumVariant(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $media = $this->createImageMedia('Couverture article medium');
        $media
            ->setThumbnailPath('/uploads/media/variants/article-cover-thumb.webp')
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/variants/article-cover-thumb.webp', 'width' => 600, 'height' => 338],
                'mobile' => ['webp' => '/uploads/media/variants/article-cover-mobile.webp', 'width' => 960, 'height' => 540],
                'medium' => ['webp' => '/uploads/media/variants/article-cover-medium.webp', 'width' => 1600, 'height' => 900],
                'large' => ['webp' => '/uploads/media/variants/article-cover-large.webp', 'width' => 1920, 'height' => 1080],
            ]);
        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole(MediaRole::Cover)
            ->setPosition(0);
        $article->getMediaLinks()->add($link);
        $media->getArticleLinks()->add($link);
        $article->setFeaturedImage($media);
        $this->persistAndFlush($article, $media, $link);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/media/variants/article-cover-medium.webp', (string) $crawler->filter('.article-show-cover')->attr('style'));
        self::assertStringNotContainsString('/uploads/media/variants/article-cover-large.webp', (string) $crawler->filter('.article-show-cover')->attr('style'));
        self::assertStringContainsString(
            '<link rel="preload" as="image" href="/uploads/media/variants/article-cover-medium.webp">',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testPublishedArticleUsesSingleWebpForCoverContentGalleryAndSharedLightbox(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $coverPath = '/uploads/media/article-cover-single.webp';
        $galleryPath = '/uploads/media/article-gallery-single.webp';
        $cover = $this->createImageMedia('Couverture Article WebP unique')
            ->setFilePath($coverPath)
            ->setThumbnailPath($coverPath)
            ->setMimeType('image/webp')
            ->setWidth(1600)
            ->setHeight(900)
            ->setVariants(null)
            ->setMetadata(['articleOptimizedSingleWebp' => true]);
        $gallery = $this->createImageMedia('Galerie Article WebP unique')
            ->setFilePath($galleryPath)
            ->setThumbnailPath($galleryPath)
            ->setMimeType('image/webp')
            ->setWidth(1600)
            ->setHeight(1067)
            ->setCaption('Légende de galerie')
            ->setVariants(null)
            ->setMetadata(['articleOptimizedSingleWebp' => true]);
        $coverLink = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($cover)
            ->setRole(MediaRole::Cover)
            ->setPosition(0);
        $galleryLink = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($gallery)
            ->setRole(MediaRole::Gallery)
            ->setPosition(1);
        $article->getMediaLinks()->add($coverLink);
        $article->getMediaLinks()->add($galleryLink);
        $cover->getArticleLinks()->add($coverLink);
        $gallery->getArticleLinks()->add($galleryLink);
        $article
            ->setFeaturedImage($cover)
            ->setContent(sprintf('<p>Introduction.</p><p>[[media:%d]]</p><p>Suite.</p>', $gallery->getId()));
        $this->persistAndFlush($article, $cover, $gallery, $coverLink, $galleryLink);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($coverPath, (string) $crawler->filter('.article-show-cover')->attr('style'));
        self::assertStringContainsString(
            sprintf('<link rel="preload" as="image" href="%s">', $coverPath),
            (string) $client->getResponse()->getContent(),
        );
        self::assertSame($galleryPath, $crawler->filter('.article-content-media img')->first()->attr('src'));
        self::assertStringContainsString(sprintf('%s 1600w', $galleryPath), (string) $crawler->filter('.article-content-media img')->first()->attr('srcset'));

        $galleryId = sprintf('article-gallery-%d', $article->getId());
        self::assertSame(1, $crawler->filter(sprintf('.journey-gallery-card[data-gallery-target="#%s"][data-gallery-index="0"]', $galleryId))->count());
        self::assertSame(1, $crawler->filter(sprintf('#%s.gallery-modal.js-gallery-modal', $galleryId))->count());
        self::assertSame($galleryPath, $crawler->filter(sprintf('#%s .gallery-modal__slide img', $galleryId))->first()->attr('data-gallery-src'));
        self::assertStringNotContainsString('/uploads/media/variants/', (string) $crawler->filter('.article-gallery-section')->html());
    }

    public function testInvalidCommentSortFallsBackWithoutServerError(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());

        $client->request('GET', sprintf('/articles/%s?comments_sort=unexpected', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $article->getTitle());
    }

    public function testUnknownArticleReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', '/articles/article-fonctionnel-inconnu');
    }
}
