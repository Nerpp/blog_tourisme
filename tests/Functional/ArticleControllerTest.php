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

        $client->request('GET', '/articles?q='.rawurlencode((string) $article->getTitle()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Articles');
        self::assertSelectorTextContains('body', (string) $article->getTitle());
    }

    public function testArticleIndexDoesNotListDraftArticles(): void
    {
        $client = static::createClient();
        $token = $this->uniqueToken('visibility');
        $published = $this->createArticle($this->createUser());
        $published->setTitle('Article visible '.$token);
        $draft = $this->createArticle($this->createUser());
        $draft
            ->setTitle('Article brouillon invisible '.$token)
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $this->persistAndFlush($published, $draft);

        $client->request('GET', '/articles?q='.rawurlencode($token));

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
        self::assertSame(0, $crawler->filter('.article-gallery-section')->count());
    }

    public function testGallerySectionIsTheNextArticleBlockAfterTheCompleteProse(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $media = $this->createImageMedia('Photo de galerie après la prose');
        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole(MediaRole::Gallery)
            ->setPosition(0);
        $article->getMediaLinks()->add($link);
        $media->getArticleLinks()->add($link);
        $article->setContent(sprintf('<p>Texte long avant la galerie avec [[media:%d]] en fin de paragraphe.</p>', $media->getId()));
        $this->persistAndFlush($article, $media, $link);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.article-show-main > .article-content + .article-gallery-section')->count());
        self::assertSame(0, $crawler->filter('.article-content .article-gallery-section')->count());
        self::assertSame(1, $crawler->filter('.article-gallery-section .journey-gallery-card')->count());
        self::assertSame(1, $crawler->filter('.article-gallery-section .gallery-modal')->count());
    }

    public function testPublishedArticleCoverIsAHighPriorityResponsiveImage(): void
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
        $coverImage = $crawler->filter('.article-show-cover picture img')->first();
        self::assertSame('/uploads/media/variants/article-cover-mobile.webp', $coverImage->attr('src'));
        self::assertSame('eager', $coverImage->attr('loading'));
        self::assertSame('high', $coverImage->attr('fetchpriority'));
        self::assertSame('async', $coverImage->attr('decoding'));
        self::assertSame('960', $coverImage->attr('width'));
        self::assertSame('540', $coverImage->attr('height'));
        self::assertSame('(min-width: 981px) 560px, (min-width: 641px) calc(100vw - 32px), calc(100vw - 24px)', $coverImage->attr('sizes'));
        self::assertSame(
            '/uploads/media/variants/article-cover-thumb.webp 600w, /uploads/media/variants/article-cover-mobile.webp 960w, /uploads/media/variants/article-cover-medium.webp 1600w, /uploads/media/variants/article-cover-large.webp 1920w',
            $coverImage->attr('srcset'),
        );
        self::assertNull($crawler->filter('.article-show-cover')->attr('style'));
        self::assertStringNotContainsString('hero-sea-mountain-desktop.webp', (string) $client->getResponse()->getContent());
        $preload = $crawler->filter('link[rel="preload"][as="image"]')->first();
        self::assertSame('/uploads/media/variants/article-cover-mobile.webp', $preload->attr('href'));
        self::assertSame('high', $preload->attr('fetchpriority'));
    }

    public function testExistingSingleWebpArticleMediaRemainReadableWithoutAutomaticConversion(): void
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
        self::assertSame($coverPath, $crawler->filter('.article-show-cover img')->first()->attr('src'));
        self::assertSame($coverPath, $crawler->filter('link[rel="preload"][as="image"]')->first()->attr('href'));
        $contentImage = $crawler->filter('.article-content-media img')->first();
        self::assertSame($galleryPath, $contentImage->attr('src'));
        self::assertStringContainsString(sprintf('%s 1600w', $galleryPath), (string) $contentImage->attr('srcset'));
        self::assertSame('lazy', $contentImage->attr('loading'));
        self::assertNull($contentImage->attr('fetchpriority'));
        self::assertSame('1600', $contentImage->attr('width'));
        self::assertSame('1067', $contentImage->attr('height'));

        $galleryId = sprintf('article-gallery-%d', $article->getId());
        self::assertSame(1, $crawler->filter(sprintf('.journey-gallery-card[data-gallery-target="#%s"][data-gallery-index="0"]', $galleryId))->count());
        self::assertSame(1, $crawler->filter(sprintf('#%s.gallery-modal.js-gallery-modal', $galleryId))->count());
        self::assertSame($galleryPath, $crawler->filter(sprintf('#%s .gallery-modal__slide img', $galleryId))->first()->attr('data-gallery-src'));
        self::assertStringNotContainsString('/uploads/media/variants/', (string) $crawler->filter('.article-gallery-section')->html());
    }

    public function testResponsiveArticleMediaUseLightCandidatesAndLoadSourceOnlyFromLightbox(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $cover = $this->responsiveArticleMedia('Couverture responsive', 'cover', 900);
        $gallery = $this->responsiveArticleMedia('Galerie responsive', 'gallery', 1067)->setCaption('Légende responsive');
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
            ->setContent(sprintf('<p>Introduction.</p><p>[[media:%d]]</p>', $gallery->getId()));
        $this->persistAndFlush($article, $cover, $gallery, $coverLink, $galleryLink);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));

        self::assertResponseIsSuccessful();
        $coverImage = $crawler->filter('.article-show-cover img')->first();
        self::assertSame('/uploads/media/article-cover-display.webp', $coverImage->attr('src'));
        self::assertSame('/uploads/media/article-cover-inline.webp 640w, /uploads/media/article-cover-display.webp 960w, /uploads/media/article-cover-cover.webp 1280w', $coverImage->attr('srcset'));
        self::assertSame('(min-width: 981px) 560px, (min-width: 641px) calc(100vw - 32px), calc(100vw - 24px)', $coverImage->attr('sizes'));
        self::assertSame('eager', $coverImage->attr('loading'));
        self::assertSame('high', $coverImage->attr('fetchpriority'));
        self::assertNotSame('lazy', $coverImage->attr('loading'));
        self::assertStringNotContainsString('article-cover-source.webp', (string) $coverImage->attr('srcset'));

        $preload = $crawler->filter('link[rel="preload"][as="image"]')->first();
        self::assertSame('/uploads/media/article-cover-display.webp', $preload->attr('href'));
        self::assertSame($coverImage->attr('srcset'), $preload->attr('imagesrcset'));
        self::assertSame($coverImage->attr('sizes'), $preload->attr('imagesizes'));

        $contentImage = $crawler->filter('.article-content-media img')->first();
        self::assertSame('/uploads/media/article-gallery-inline.webp', $contentImage->attr('src'));
        self::assertSame('/uploads/media/article-gallery-inline.webp 640w, /uploads/media/article-gallery-display.webp 960w, /uploads/media/article-gallery-cover.webp 1280w', $contentImage->attr('srcset'));
        self::assertSame('lazy', $contentImage->attr('loading'));
        self::assertNull($contentImage->attr('fetchpriority'));
        self::assertStringNotContainsString('article-gallery-source.webp', (string) $contentImage->attr('srcset'));

        $galleryCardImage = $crawler->filter('.article-gallery-section .journey-gallery-card img')->first();
        self::assertSame('lazy', $galleryCardImage->attr('loading'));
        self::assertSame('async', $galleryCardImage->attr('decoding'));
        self::assertNull($galleryCardImage->attr('fetchpriority'));
        self::assertNotNull($galleryCardImage->attr('width'));
        self::assertNotNull($galleryCardImage->attr('height'));
        self::assertStringNotContainsString('article-gallery-source.webp', (string) $galleryCardImage->attr('srcset'));
        self::assertSame(
            'false',
            $crawler->filter('.article-gallery-section .gallery-modal')->first()->attr('data-gallery-preload-neighbors'),
        );
        self::assertSame(
            '/uploads/media/article-gallery-source.webp',
            $crawler->filter('.article-gallery-section .gallery-modal__slide img')->first()->attr('data-gallery-src'),
        );
        self::assertNull($crawler->filter('.article-gallery-section .gallery-modal__slide img')->first()->attr('src'));
    }

    public function testResponsiveArticleCoverStillRendersWhenTheDisplayVariantIsMissing(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $cover = $this->responsiveArticleMedia('Couverture responsive incomplète', 'incomplete-cover', 900);
        $variants = $cover->getVariants();
        self::assertIsArray($variants);
        unset($variants['mobile']);
        $cover->setVariants($variants);

        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($cover)
            ->setRole(MediaRole::Cover)
            ->setPosition(0);
        $article->getMediaLinks()->add($link);
        $cover->getArticleLinks()->add($link);
        $article->setFeaturedImage($cover);
        $this->persistAndFlush($article, $cover, $link);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));

        self::assertResponseIsSuccessful();
        $coverImage = $crawler->filter('.article-show-cover img')->first();
        self::assertSame('/uploads/media/article-incomplete-cover-inline.webp', $coverImage->attr('src'));
        self::assertSame(
            '/uploads/media/article-incomplete-cover-inline.webp 640w, /uploads/media/article-incomplete-cover-cover.webp 1280w',
            $coverImage->attr('srcset'),
        );
        self::assertSame('eager', $coverImage->attr('loading'));
        self::assertSame('high', $coverImage->attr('fetchpriority'));
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

    private function responsiveArticleMedia(string $title, string $slug, int $sourceHeight): \App\Entity\MediaAsset
    {
        $media = $this->createImageMedia($title)
            ->setFilePath(sprintf('/uploads/media/article-%s-source.webp', $slug))
            ->setThumbnailPath(sprintf('/uploads/media/article-%s-inline.webp', $slug))
            ->setMimeType('image/webp')
            ->setWidth(1600)
            ->setHeight($sourceHeight)
            ->setVariants([
                'thumb' => ['webp' => sprintf('/uploads/media/article-%s-inline.webp', $slug), 'width' => 640, 'height' => (int) round($sourceHeight * 0.4)],
                'mobile' => ['webp' => sprintf('/uploads/media/article-%s-display.webp', $slug), 'width' => 960, 'height' => (int) round($sourceHeight * 0.6)],
                'medium' => ['webp' => sprintf('/uploads/media/article-%s-cover.webp', $slug), 'width' => 1280, 'height' => (int) round($sourceHeight * 0.8)],
                'large' => ['webp' => sprintf('/uploads/media/article-%s-source.webp', $slug), 'width' => 1600, 'height' => $sourceHeight],
            ])
            ->setMetadata(['articleResponsiveWebp' => true]);
        $this->persistAndFlush($media);

        return $media;
    }
}
