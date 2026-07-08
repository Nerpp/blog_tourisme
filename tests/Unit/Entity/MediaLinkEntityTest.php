<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleDestination;
use App\Entity\ArticleHike;
use App\Entity\ArticleMedia;
use App\Entity\ArticlePlace;
use App\Entity\Category;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use App\Entity\Tag;
use App\Enum\CategoryType;
use App\Enum\DestinationType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaLinkEntityTest extends TestCase
{
    public function testArticleDestinationStoresArticleDestinationAndPosition(): void
    {
        $article = (new Article())->setTitle('Article');
        $destination = (new Destination())->setName('Collioure')->setSlug('collioure')->setType(DestinationType::City);

        $link = (new ArticleDestination())
            ->setArticle($article)
            ->setDestination($destination)
            ->setPosition(3);

        self::assertNull($link->getId());
        self::assertSame($article, $link->getArticle());
        self::assertSame($destination, $link->getDestination());
        self::assertSame(3, $link->getPosition());
    }

    public function testArticleMediaStoresMediaPositionAndRole(): void
    {
        $article = new Article();
        $media = $this->image();

        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setPosition(2);

        self::assertSame(MediaRole::Content, $link->getRole());
        self::assertSame($article, $link->getArticle());
        self::assertSame($media, $link->getMediaAsset());
        self::assertSame(2, $link->getPosition());

        $link->setRole(MediaRole::Cover);
        self::assertSame(MediaRole::Cover, $link->getRole());
    }

    public function testPlaceMediaStoresPlaceMediaPositionAndRole(): void
    {
        $place = new Place();
        $media = $this->image();

        $link = (new PlaceMedia())
            ->setPlace($place)
            ->setMediaAsset($media)
            ->setPosition(4);

        self::assertSame(MediaRole::Gallery, $link->getRole());
        self::assertSame($place, $link->getPlace());
        self::assertSame($media, $link->getMediaAsset());
        self::assertSame(4, $link->getPosition());

        $link->setRole(MediaRole::Cover);
        self::assertSame(MediaRole::Cover, $link->getRole());
    }

    public function testHikeDraftMediaStoresAndOwnerCanAvoidDuplicates(): void
    {
        $hike = new HikeDraft();
        $media = $this->image();
        $link = (new HikeDraftMedia())
            ->setMediaAsset($media)
            ->setPosition(5)
            ->setRole(MediaRole::Cover);

        $hike->addMediaLink($link);
        $hike->addMediaLink($link);

        self::assertSame($hike, $link->getHikeDraft());
        self::assertSame($media, $link->getMediaAsset());
        self::assertSame(5, $link->getPosition());
        self::assertSame(MediaRole::Cover, $link->getRole());
        self::assertCount(1, $hike->getMediaLinks());

        $hike->removeMediaLink($link);
        self::assertCount(0, $hike->getMediaLinks());
    }

    public function testCityVisitDraftMediaStoresAndOwnerCanAvoidDuplicates(): void
    {
        $cityVisit = new CityVisitDraft();
        $media = $this->image();
        $link = (new CityVisitDraftMedia())
            ->setMediaAsset($media)
            ->setPosition(6)
            ->setRole(MediaRole::Gallery);

        $cityVisit->addMediaLink($link);
        $cityVisit->addMediaLink($link);

        self::assertSame($cityVisit, $link->getCityVisitDraft());
        self::assertSame($media, $link->getMediaAsset());
        self::assertSame(6, $link->getPosition());
        self::assertSame(MediaRole::Gallery, $link->getRole());
        self::assertCount(1, $cityVisit->getMediaLinks());

        $cityVisit->removeMediaLink($link);
        self::assertCount(0, $cityVisit->getMediaLinks());
    }

    public function testArticleRelatedContentLinksStoreDefaultsAndFallbackRoles(): void
    {
        $article = new Article();
        $hike = new HikeDraft();
        $cityVisit = new CityVisitDraft();
        $place = new Place();

        $hikeLink = (new ArticleHike())
            ->setArticle($article)
            ->setHikeDraft($hike)
            ->setPosition(1)
            ->setRole(' featured ');
        $cityVisitLink = (new ArticleCityVisit())
            ->setArticle($article)
            ->setCityVisitDraft($cityVisit)
            ->setPosition(2)
            ->setRole('   ');
        $placeLink = (new ArticlePlace())
            ->setArticle($article)
            ->setPlace($place)
            ->setPosition(3);

        self::assertSame($article, $hikeLink->getArticle());
        self::assertSame($hike, $hikeLink->getHikeDraft());
        self::assertSame(1, $hikeLink->getPosition());
        self::assertSame('featured', $hikeLink->getRole());

        self::assertSame($article, $cityVisitLink->getArticle());
        self::assertSame($cityVisit, $cityVisitLink->getCityVisitDraft());
        self::assertSame(2, $cityVisitLink->getPosition());
        self::assertSame('related', $cityVisitLink->getRole());

        self::assertSame($article, $placeLink->getArticle());
        self::assertSame($place, $placeLink->getPlace());
        self::assertSame(3, $placeLink->getPosition());
    }

    public function testTagAndCategoryExposeLabelsCollectionsAndTimestamps(): void
    {
        $tag = (new Tag())->setName('Mer')->setSlug('mer');
        $category = (new Category())
            ->setName('Patrimoine')
            ->setSlug('patrimoine')
            ->setType(CategoryType::Place)
            ->setDescription('Lieux à visiter');

        self::assertSame('Mer', (string) $tag);
        self::assertSame('mer', $tag->getSlug());
        self::assertCount(0, $tag->getArticleTags());
        self::assertCount(0, $tag->getPlaceTags());

        self::assertSame('Patrimoine', (string) $category);
        self::assertSame('patrimoine', $category->getSlug());
        self::assertSame(CategoryType::Place, $category->getType());
        self::assertSame('Lieux à visiter', $category->getDescription());
        self::assertCount(0, $category->getArticles());
        self::assertCount(0, $category->getPlaces());

        $tag->initializeTimestamps();
        $category->initializeTimestamps();
        self::assertNotNull($tag->getCreatedAt());
        self::assertNotNull($category->getUpdatedAt());
    }

    private function image(): MediaAsset
    {
        return (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/test.jpg');
    }
}
