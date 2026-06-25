<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleHike;
use App\Entity\ArticleMedia;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Service\HomepageLatestContentProvider;
use App\Service\PublicContentUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class HomepageLatestContentProviderTest extends TestCase
{
    public function testReturnsNullWhenNoRepositoryProvidesContent(): void
    {
        $provider = $this->provider();

        self::assertNull($provider->getLatest());
    }

    public function testReturnsNewestContentAcrossArticlesHikesAndCityVisits(): void
    {
        $article = $this->article('Article recent', 'article-recent', new \DateTimeImmutable('-3 days'));
        $hikeMedia = $this->image('/uploads/hike-original.jpg', '/uploads/hike.webp');
        $hike = $this->hike('Hike newest', 'hike-newest', new \DateTimeImmutable('-1 day'), $hikeMedia);
        $cityVisit = $this->cityVisit('City older', 'city-older', new \DateTimeImmutable('-2 days'));

        $latest = $this->provider($article, $hike, $cityVisit)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('hike', $latest['type']);
        self::assertSame('Dernière randonnée', $latest['label']);
        self::assertSame('Hike newest', $latest['title']);
        self::assertSame('/hikes/hike-newest', $latest['url']);
        self::assertSame('/uploads/hike.webp', $latest['image']);
        self::assertSame($hikeMedia, $latest['media']);
        self::assertSame('Découvrir la balade', $latest['primary_cta']);
    }

    public function testArticleUsesFeaturedThumbnailBeforeOriginalAndExternalUrl(): void
    {
        $media = $this->image('/uploads/original.jpg', '/uploads/thumb.webp', 'https://cdn.example.test/fallback.jpg');
        $article = $this->article('Article newest', 'article-newest', new \DateTimeImmutable('-1 day'), $media);

        $latest = $this->provider($article)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('article', $latest['type']);
        self::assertSame('/uploads/thumb.webp', $latest['image']);
        self::assertSame($media, $latest['media']);
        self::assertSame('/articles/article-newest', $latest['url']);
    }

    public function testArticleLinkedToPublicHikePromotesTheHikeCardWithArticleSecondaryLink(): void
    {
        $galleryMedia = $this->image('/uploads/hike-gallery.jpg', '/uploads/hike-gallery-thumb.webp');
        $coverMedia = $this->image('/uploads/hike-cover.jpg', '/uploads/hike-cover-thumb.webp');
        $article = $this->article('Carnet de balade', 'carnet-balade', new \DateTimeImmutable('-1 day'));
        $hike = $this->hike('Sentier du lac', 'sentier-du-lac', new \DateTimeImmutable('-10 days'));
        $hike->getMediaLinks()->add((new HikeDraftMedia())->setHikeDraft($hike)->setMediaAsset($galleryMedia));
        $hike->getMediaLinks()->add(
            (new HikeDraftMedia())
                ->setHikeDraft($hike)
                ->setMediaAsset($coverMedia)
                ->setRole(MediaRole::Cover),
        );
        $article->getHikeLinks()->add((new ArticleHike())->setArticle($article)->setHikeDraft($hike));

        $latest = $this->provider($article)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('hike', $latest['type']);
        self::assertSame('Nouvel article', $latest['label']);
        self::assertSame('Sentier du lac', $latest['title']);
        self::assertSame('/hikes/sentier-du-lac', $latest['url']);
        self::assertSame('/uploads/hike-cover-thumb.webp', $latest['image']);
        self::assertSame($coverMedia, $latest['media']);
        self::assertSame('Découvrir la balade', $latest['primary_cta']);
        self::assertSame('Carnet de balade', $latest['secondary_title']);
        self::assertSame('/articles/carnet-balade', $latest['secondary_url']);
        self::assertSame('Lire l’article', $latest['secondary_cta']);
    }

    public function testArticleSkipsDraftLinkedHikeAndPromotesPublicCityVisit(): void
    {
        $article = $this->article('Carnet urbain', 'carnet-urbain', new \DateTimeImmutable('-1 day'));
        $draftHike = $this->hike('Brouillon randonnée', 'brouillon-randonnee', new \DateTimeImmutable('-2 days'));
        $draftHike->setStatus(HikeDraftStatus::Draft);
        $cityVisit = $this->cityVisit(
            'Balade en ville',
            'balade-en-ville',
            new \DateTimeImmutable('-5 days'),
            $this->image('/uploads/city.jpg', '/uploads/city-thumb.webp'),
        );
        $cityVisit->setStatus(CityVisitDraftStatus::Converted);
        $article->getHikeLinks()->add((new ArticleHike())->setArticle($article)->setHikeDraft($draftHike));
        $article->getCityVisitLinks()->add((new ArticleCityVisit())->setArticle($article)->setCityVisitDraft($cityVisit));

        $latest = $this->provider($article)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('city_visit', $latest['type']);
        self::assertSame('Balade en ville', $latest['title']);
        self::assertSame('/city-visits/balade-en-ville', $latest['url']);
        self::assertSame('/uploads/city-thumb.webp', $latest['image']);
        self::assertSame('Découvrir la visite', $latest['primary_cta']);
        self::assertSame('/articles/carnet-urbain', $latest['secondary_url']);
    }

    public function testArticleCoverMediaBeatsFeaturedImageAndIgnoresVideoMedia(): void
    {
        $featured = $this->image('/uploads/featured.jpg', '/uploads/featured-thumb.webp');
        $gallery = $this->image('/uploads/gallery.jpg', '/uploads/gallery-thumb.webp');
        $cover = $this->image('/uploads/cover.jpg', '/uploads/cover-thumb.webp');
        $video = (new MediaAsset())->setMediaType(MediaType::Video)->setThumbnailPath('/uploads/video-thumb.jpg');
        $article = $this->article('Article media', 'article-media', new \DateTimeImmutable('-1 day'), $featured);
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($video));
        $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($gallery));
        $article->getMediaLinks()->add(
            (new ArticleMedia())
                ->setArticle($article)
                ->setMediaAsset($cover)
                ->setRole(MediaRole::Cover),
        );

        $latest = $this->provider($article)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('article', $latest['type']);
        self::assertSame('/uploads/cover-thumb.webp', $latest['image']);
        self::assertSame($cover, $latest['media']);
    }

    public function testSpecialImagesAreIgnoredAndStandardWithoutWebpUsesPlaceholder(): void
    {
        $specialImage = $this->image('/uploads/panorama.jpg')->setImageType(ImageType::Panorama);
        $standardImage = $this->image('/uploads/standard.jpg')->setImageType(ImageType::Standard);
        $specialArticle = $this->article('Panorama', 'panorama', new \DateTimeImmutable('-2 days'), $specialImage);
        $standardArticle = $this->article('Standard', 'standard', new \DateTimeImmutable('-1 day'), $standardImage);

        $specialLatest = $this->provider($specialArticle)->getLatest();
        $standardLatest = $this->provider($standardArticle)->getLatest();

        self::assertIsArray($specialLatest);
        self::assertIsArray($standardLatest);
        self::assertNull($specialLatest['image']);
        self::assertNull($specialLatest['media']);
        self::assertSame('/images/placeholders/destination-card-placeholder.webp', $standardLatest['image']);
        self::assertSame($standardImage, $standardLatest['media']);
    }

    public function testSpecialCoverIsIgnoredInFavorOfStandardGalleryWebp(): void
    {
        $special = $this->image('/uploads/360.jpg')->setImageType(ImageType::Degree360);
        $standard = $this->image('/uploads/standard.jpg', '/uploads/standard-thumb.webp');
        $article = $this->article('Article', 'article', new \DateTimeImmutable('-1 day'), $special);
        $article->getMediaLinks()->add(
            (new ArticleMedia())
                ->setArticle($article)
                ->setMediaAsset($standard)
                ->setRole(MediaRole::Gallery),
        );

        $latest = $this->provider($article)->getLatest();

        self::assertIsArray($latest);
        self::assertSame($standard, $latest['media']);
        self::assertSame('/uploads/standard-thumb.webp', $latest['image']);
    }

    public function testIgnoresItemsWithoutDatesOrUrls(): void
    {
        $articleWithoutDate = $this->article('No date', 'no-date', null);
        $hikeWithoutSlug = $this->hike('No slug', '', new \DateTimeImmutable('-1 day'));

        self::assertNull($this->provider($articleWithoutDate, $hikeWithoutSlug)->getLatest());
    }

    private function provider(
        ?Article $article = null,
        ?HikeDraft $hike = null,
        ?CityVisitDraft $cityVisit = null,
    ): HomepageLatestContentProvider {
        $articleRepository = $this->createStub(ArticleRepository::class);
        $articleRepository->method('findLatestPublishedForHomepage')->willReturn($article);

        $hikeRepository = $this->createStub(HikeDraftRepository::class);
        $hikeRepository->method('findLatestFinishedForHomepage')->willReturn($hike);

        $cityVisitRepository = $this->createStub(CityVisitDraftRepository::class);
        $cityVisitRepository->method('findLatestFinishedForHomepage')->willReturn($cityVisit);

        return new HomepageLatestContentProvider(
            $articleRepository,
            $hikeRepository,
            $cityVisitRepository,
            new PublicContentUrlResolver(new TestUrlGenerator()),
        );
    }

    private function article(string $title, string $slug, ?\DateTimeImmutable $publishedAt, ?MediaAsset $media = null): Article
    {
        return (new Article())
            ->setTitle($title)
            ->setSlug($slug)
            ->setContent('<p>Content</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt($publishedAt)
            ->setFeaturedImage($media);
    }

    private function hike(string $title, string $slug, ?\DateTimeImmutable $finishedAt, ?MediaAsset $media = null): HikeDraft
    {
        $hike = (new HikeDraft())
            ->setTitle($title)
            ->setSlug($slug)
            ->setStatus(HikeDraftStatus::Finished)
            ->setFinishedAt($finishedAt);

        if ($media instanceof MediaAsset) {
            $hike->getMediaLinks()->add((new HikeDraftMedia())->setHikeDraft($hike)->setMediaAsset($media));
        }

        return $hike;
    }

    private function cityVisit(string $title, string $slug, ?\DateTimeImmutable $finishedAt, ?MediaAsset $media = null): CityVisitDraft
    {
        $cityVisit = (new CityVisitDraft())
            ->setTitle($title)
            ->setSlug($slug)
            ->setStatus(CityVisitDraftStatus::Finished)
            ->setFinishedAt($finishedAt);

        if ($media instanceof MediaAsset) {
            $cityVisit->getMediaLinks()->add((new CityVisitDraftMedia())->setCityVisitDraft($cityVisit)->setMediaAsset($media));
        }

        return $cityVisit;
    }

    private function image(?string $filePath, ?string $thumbnailPath = null, ?string $externalUrl = null): MediaAsset
    {
        return (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($filePath)
            ->setThumbnailPath($thumbnailPath)
            ->setVariants($thumbnailPath !== null ? [
                'thumb' => [
                    'webp' => $thumbnailPath,
                    'width' => 600,
                    'height' => 400,
                ],
            ] : null)
            ->setExternalUrl($externalUrl);
    }
}

final class TestUrlGenerator implements UrlGeneratorInterface
{
    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext();
    }

    /** @param array<string, mixed> $parameters */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $slug = (string) ($parameters['slug'] ?? '');
        if ($slug === '') {
            return '';
        }

        return match ($name) {
            'app_article_show' => '/articles/'.$slug,
            'app_hike_show' => '/hikes/'.$slug,
            'app_city_visit_show' => '/city-visits/'.$slug,
            default => '/'.$slug,
        };
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
