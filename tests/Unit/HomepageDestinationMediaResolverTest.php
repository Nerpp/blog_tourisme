<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use App\Service\HomepageDestinationMediaResolver;
use PHPUnit\Framework\TestCase;

final class HomepageDestinationMediaResolverTest extends TestCase
{
    public function testReturnsNullWhenNoCandidateHasImageMedia(): void
    {
        self::assertNull($this->resolver()->representativeMedia($this->destination()));
    }

    public function testChoosesMostRecentImageCandidateAcrossContentTypes(): void
    {
        $articleMedia = $this->image('/uploads/article.jpg');
        $hikeMedia = $this->image('/uploads/hike.jpg');
        $cityVisitMedia = $this->image('/uploads/city.jpg');
        $placeMedia = $this->image('/uploads/place.jpg');

        $resolver = $this->resolver(
            article: $this->article(new \DateTimeImmutable('-4 days'), featuredImage: $articleMedia),
            hike: $this->hike(new \DateTimeImmutable('-1 day'), $hikeMedia),
            cityVisit: $this->cityVisit(new \DateTimeImmutable('-2 days'), $cityVisitMedia),
            place: $this->place(new \DateTimeImmutable('-3 days'), featuredImage: $placeMedia),
        );

        self::assertSame($hikeMedia, $resolver->representativeMedia($this->destination()));
    }

    public function testArticleFallsBackFromVideoFeaturedImageToFirstImageLink(): void
    {
        $linkedImage = $this->image('/uploads/linked.jpg');
        $article = $this->article(
            new \DateTimeImmutable('-1 day'),
            featuredImage: (new MediaAsset())->setMediaType(MediaType::Video)->setExternalUrl('https://youtu.be/abcDEF_1234'),
            linkedMedia: [$linkedImage],
        );

        self::assertSame($linkedImage, $this->resolver(article: $article)->representativeMedia($this->destination()));
    }

    public function testPlaceFallsBackFromFeaturedImageToFirstImageLink(): void
    {
        $linkedImage = $this->image('/uploads/place-linked.jpg');
        $place = $this->place(
            new \DateTimeImmutable('-1 day'),
            featuredImage: (new MediaAsset())->setMediaType(MediaType::Video)->setExternalUrl('https://youtu.be/abcDEF_1234'),
            linkedMedia: [$linkedImage],
        );

        self::assertSame($linkedImage, $this->resolver(place: $place)->representativeMedia($this->destination()));
    }

    public function testSpecialCoverIsIgnoredInFavorOfStandardGalleryImage(): void
    {
        $specialCover = $this->image('/uploads/panorama.jpg')->setImageType(ImageType::Panorama);
        $standardGallery = $this->image('/uploads/standard.webp');
        $hike = $this->hike(new \DateTimeImmutable('-1 day'), $specialCover);
        $coverLink = $hike->getMediaLinks()->first();
        self::assertInstanceOf(HikeDraftMedia::class, $coverLink);
        $coverLink->setRole(\App\Enum\MediaRole::Cover);
        $hike->getMediaLinks()->add(
            (new HikeDraftMedia())
                ->setHikeDraft($hike)
                ->setMediaAsset($standardGallery)
                ->setRole(\App\Enum\MediaRole::Gallery),
        );

        self::assertSame($standardGallery, $this->resolver(hike: $hike)->representativeMedia($this->destination()));
    }

    public function testReturnsNullWhenOnlySpecialImagesExist(): void
    {
        $special = $this->image('/uploads/360.jpg')->setImageType(ImageType::Degree360);

        self::assertNull(
            $this->resolver(hike: $this->hike(new \DateTimeImmutable('-1 day'), $special))
                ->representativeMedia($this->destination()),
        );
    }

    public function testVideoPosterMediaIsNotUsedAsHomepageDestinationCardImage(): void
    {
        $video = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath('/uploads/media/posters/video-poster.jpg');

        self::assertNull(
            $this->resolver(hike: $this->hike(new \DateTimeImmutable('-1 day'), $video))
                ->representativeMedia($this->destination()),
        );
    }

    private function resolver(
        ?Article $article = null,
        ?HikeDraft $hike = null,
        ?CityVisitDraft $cityVisit = null,
        ?Place $place = null,
    ): HomepageDestinationMediaResolver {
        $articleRepository = $this->createStub(ArticleRepository::class);
        $articleRepository->method('findLatestPublishedWithMediaByDestination')->willReturn($article);

        $hikeRepository = $this->createStub(HikeDraftRepository::class);
        $hikeRepository->method('findLatestPublicWithMediaByDestination')->willReturn($hike);

        $cityVisitRepository = $this->createStub(CityVisitDraftRepository::class);
        $cityVisitRepository->method('findLatestPublicWithMediaByDestination')->willReturn($cityVisit);

        $placeRepository = $this->createStub(PlaceRepository::class);
        $placeRepository->method('findLatestPublishedWithMediaByDestination')->willReturn($place);

        return new HomepageDestinationMediaResolver($articleRepository, $hikeRepository, $cityVisitRepository, $placeRepository);
    }

    private function destination(): Destination
    {
        return (new Destination())
            ->setName('Destination')
            ->setSlug('destination')
            ->setType(DestinationType::Area);
    }

    /** @param list<MediaAsset> $linkedMedia */
    private function article(
        \DateTimeImmutable $publishedAt,
        ?MediaAsset $featuredImage = null,
        array $linkedMedia = [],
    ): Article {
        $article = (new Article())
            ->setTitle('Article')
            ->setSlug('article')
            ->setContent('<p>Content</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt($publishedAt)
            ->setFeaturedImage($featuredImage);

        foreach ($linkedMedia as $index => $media) {
            $article->getMediaLinks()->add((new ArticleMedia())->setArticle($article)->setMediaAsset($media)->setPosition($index));
        }

        return $article;
    }

    private function hike(\DateTimeImmutable $finishedAt, MediaAsset $media): HikeDraft
    {
        $hike = (new HikeDraft())
            ->setTitle('Hike')
            ->setSlug('hike')
            ->setStatus(HikeDraftStatus::Finished)
            ->setFinishedAt($finishedAt);
        $hike->getMediaLinks()->add((new HikeDraftMedia())->setHikeDraft($hike)->setMediaAsset($media));

        return $hike;
    }

    private function cityVisit(\DateTimeImmutable $finishedAt, MediaAsset $media): CityVisitDraft
    {
        $cityVisit = (new CityVisitDraft())
            ->setTitle('City')
            ->setSlug('city')
            ->setStatus(CityVisitDraftStatus::Finished)
            ->setFinishedAt($finishedAt);
        $cityVisit->getMediaLinks()->add((new CityVisitDraftMedia())->setCityVisitDraft($cityVisit)->setMediaAsset($media));

        return $cityVisit;
    }

    /** @param list<MediaAsset> $linkedMedia */
    private function place(
        \DateTimeImmutable $publishedAt,
        ?MediaAsset $featuredImage = null,
        array $linkedMedia = [],
    ): Place {
        $place = (new Place())
            ->setName('Place')
            ->setSlug('place')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt($publishedAt)
            ->setDifficulty(PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::Unknown)
            ->setFeaturedImage($featuredImage);

        foreach ($linkedMedia as $index => $media) {
            $place->getMediaLinks()->add((new PlaceMedia())->setPlace($place)->setMediaAsset($media)->setPosition($index));
        }

        return $place;
    }

    private function image(string $path): MediaAsset
    {
        return (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($path);
    }
}
