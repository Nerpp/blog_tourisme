<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPoint;
use App\Entity\CityVisitPointMedia;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePoint;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Service\Instagram\InstagramMedia;
use App\Service\Instagram\InstagramMediaCollector;
use App\Service\Instagram\MediaPublicUrlResolver;
use App\Service\Seo\PublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

final class InstagramMediaCollectorTest extends TestCase
{
    public function testItCollectsHikePhotosInDomainOrderAndDeduplicatesByIdThenUrl(): void
    {
        $hike = (new HikeDraft())->setTitle('Randonnée');
        $cover = $this->image(1, '/uploads/media/cover.webp');
        $directFirstById = $this->image(2, '/uploads/media/direct-first.webp');
        $directSecondById = $this->image(3, '/uploads/media/direct-second.webp');
        $wideAngle = $this->image(4, '/uploads/media/wide.webp', ImageType::WideAngle);

        $this->addHikeDirectMedia($hike, $directSecondById, MediaRole::Gallery, 1, 30);
        $this->addHikeDirectMedia($hike, $wideAngle, MediaRole::Gallery, 2, 40);
        $this->addHikeDirectMedia($hike, $cover, MediaRole::Cover, 99, 50);
        $this->addHikeDirectMedia($hike, $directFirstById, MediaRole::Gallery, 1, 20);
        $this->addHikeDirectMedia($hike, $this->image(5, '/uploads/media/video.webp')->setMediaType(MediaType::Video), MediaRole::Gallery, 3, 60);
        $this->addHikeDirectMedia($hike, $this->image(6, '/uploads/media/360.webp', ImageType::Degree360), MediaRole::Gallery, 4, 61);
        $this->addHikeDirectMedia($hike, $this->image(7, '/uploads/media/180.webp', ImageType::Degree180), MediaRole::Gallery, 5, 62);
        $this->addHikeDirectMedia($hike, $this->image(8, '/uploads/media/panorama.webp', ImageType::Panorama), MediaRole::Gallery, 6, 63);

        $secondPoint = (new HikePoint())->setPosition(2);
        $firstPoint = (new HikePoint())->setPosition(1);
        $hike->addPoint($secondPoint)->addPoint($firstPoint);

        $pointEarlier = $this->image(9, '/uploads/media/point-earlier.webp');
        $pointLater = $this->image(10, '/uploads/media/point-later.webp');
        $this->addHikePointMedia($firstPoint, $pointLater, new \DateTimeImmutable('2026-01-02 10:00:00'), 80);
        $this->addHikePointMedia($firstPoint, $pointEarlier, new \DateTimeImmutable('2026-01-01 10:00:00'), 90);
        $this->addHikePointMedia($firstPoint, $cover, new \DateTimeImmutable('2025-12-31 10:00:00'), 70);
        $this->addHikePointMedia(
            $firstPoint,
            $this->image(11, '/uploads/media/point-earlier.webp'),
            new \DateTimeImmutable('2026-01-03 10:00:00'),
            100,
        );

        $legacy = $this->image(12, '/uploads/media/legacy.webp', null);
        $this->addHikePointMedia($secondPoint, $legacy, new \DateTimeImmutable('2026-01-01 10:00:00'), 110);

        $result = $this->collector()->collect($hike);

        self::assertSame([
            'https://estela-exploration.fr/uploads/media/cover.webp',
            'https://estela-exploration.fr/uploads/media/direct-first.webp',
            'https://estela-exploration.fr/uploads/media/direct-second.webp',
            'https://estela-exploration.fr/uploads/media/wide.webp',
            'https://estela-exploration.fr/uploads/media/point-earlier.webp',
            'https://estela-exploration.fr/uploads/media/point-later.webp',
            'https://estela-exploration.fr/uploads/media/legacy.webp',
        ], array_map(static fn(InstagramMedia $media): string => $media->url, $result));
        self::assertSame($cover, $result[0]->mediaAsset);
        self::assertSame([1, 2, 3, 4, 9, 10, 12], array_map(
            static fn(InstagramMedia $media): ?int => $media->mediaId,
            $result,
        ));
    }

    public function testItSupportsCityVisitsAndSortsPointsAndTheirMediaExplicitly(): void
    {
        $cityVisit = (new CityVisitDraft())->setTitle('Visite');
        $direct = $this->image(20, '/uploads/media/city-direct.webp');
        $this->addCityDirectMedia($cityVisit, $direct, MediaRole::Gallery, 1, 1);

        $secondPoint = (new CityVisitPoint())->setPosition(2);
        $firstPoint = (new CityVisitPoint())->setPosition(1);
        $cityVisit->addPoint($secondPoint)->addPoint($firstPoint);

        $pointFirstById = $this->image(21, '/uploads/media/city-point-first.webp');
        $pointSecondById = $this->image(22, '/uploads/media/city-point-second.webp');
        $sameDate = new \DateTimeImmutable('2026-02-01 12:00:00');
        $this->addCityPointMedia($firstPoint, $pointSecondById, $sameDate, 5);
        $this->addCityPointMedia($firstPoint, $pointFirstById, $sameDate, 4);

        $last = $this->image(23, '/uploads/media/city-last.webp');
        $this->addCityPointMedia($secondPoint, $last, new \DateTimeImmutable('2026-01-01'), 2);

        self::assertSame([20, 21, 22, 23], array_map(
            static fn(InstagramMedia $media): ?int => $media->mediaId,
            $this->collector()->collect($cityVisit),
        ));
    }

    public function testItReturnsNoMediaWhenEveryCandidateIsIncompatibleOrUnusable(): void
    {
        $cityVisit = (new CityVisitDraft())->setTitle('Visite');
        $this->addCityDirectMedia(
            $cityVisit,
            $this->image(30, '/uploads/media/video.jpg')->setMediaType(MediaType::Video),
            MediaRole::Cover,
            0,
            1,
        );
        $this->addCityDirectMedia(
            $cityVisit,
            $this->image(33, '/uploads/media/external-video-thumbnail.jpg')
                ->setMediaType(MediaType::Video)
                ->setExternalUrl('https://video.example.test/watch/33'),
            MediaRole::Gallery,
            1,
            4,
        );
        $this->addCityDirectMedia(
            $cityVisit,
            $this->image(31, '/images/placeholders/destination-card-placeholder.webp'),
            MediaRole::Gallery,
            2,
            2,
        );
        $this->addCityDirectMedia(
            $cityVisit,
            $this->image(32, 'http://cdn.example.test/insecure.webp'),
            MediaRole::Gallery,
            3,
            3,
        );

        self::assertSame([], $this->collector()->collect($cityVisit));
    }

    private function collector(): InstagramMediaCollector
    {
        $publicUrlGenerator = new PublicUrlGenerator(
            $this->createStub(RouterInterface::class),
            'https://estela-exploration.fr',
        );

        return new InstagramMediaCollector(new MediaPublicUrlResolver($publicUrlGenerator));
    }

    private function image(int $id, string $filePath, ?ImageType $imageType = ImageType::Standard): MediaAsset
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType($imageType)
            ->setFilePath($filePath);
        $this->setId($media, $id);

        return $media;
    }

    private function addHikeDirectMedia(
        HikeDraft $hike,
        MediaAsset $media,
        MediaRole $role,
        int $position,
        int $linkId,
    ): void {
        $link = (new HikeDraftMedia())
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $this->setId($link, $linkId);
        $hike->addMediaLink($link);
    }

    private function addCityDirectMedia(
        CityVisitDraft $cityVisit,
        MediaAsset $media,
        MediaRole $role,
        int $position,
        int $linkId,
    ): void {
        $link = (new CityVisitDraftMedia())
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $this->setId($link, $linkId);
        $cityVisit->addMediaLink($link);
    }

    private function addHikePointMedia(
        HikePoint $point,
        MediaAsset $media,
        \DateTimeImmutable $createdAt,
        int $linkId,
    ): void {
        $link = (new HikePointMedia())
            ->setMediaAsset($media)
            ->setCreatedAt($createdAt);
        $this->setId($link, $linkId);
        $point->addMediaLink($link);
    }

    private function addCityPointMedia(
        CityVisitPoint $point,
        MediaAsset $media,
        \DateTimeImmutable $createdAt,
        int $linkId,
    ): void {
        $link = (new CityVisitPointMedia())
            ->setMediaAsset($media)
            ->setCreatedAt($createdAt);
        $this->setId($link, $linkId);
        $point->addMediaLink($link);
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
