<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
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
        $hike = $this->hike('Hike newest', 'hike-newest', new \DateTimeImmutable('-1 day'), $this->image('/uploads/hike.jpg'));
        $cityVisit = $this->cityVisit('City older', 'city-older', new \DateTimeImmutable('-2 days'));

        $latest = $this->provider($article, $hike, $cityVisit)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('hike', $latest['type']);
        self::assertSame('Dernière randonnée', $latest['label']);
        self::assertSame('Hike newest', $latest['title']);
        self::assertSame('/hikes/hike-newest', $latest['url']);
        self::assertSame('/uploads/hike.jpg', $latest['image']);
        self::assertSame('Découvrir la balade', $latest['primary_cta']);
    }

    public function testArticleUsesFeaturedThumbnailBeforeOriginalAndExternalUrl(): void
    {
        $media = $this->image('/uploads/original.jpg', '/uploads/thumb.jpg', 'https://cdn.example.test/fallback.jpg');
        $article = $this->article('Article newest', 'article-newest', new \DateTimeImmutable('-1 day'), $media);

        $latest = $this->provider($article)->getLatest();

        self::assertIsArray($latest);
        self::assertSame('article', $latest['type']);
        self::assertSame('/uploads/thumb.jpg', $latest['image']);
        self::assertSame('/articles/article-newest', $latest['url']);
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
            ->setFilePath($filePath)
            ->setThumbnailPath($thumbnailPath)
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
