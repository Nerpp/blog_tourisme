<?php

namespace App\Tests\Unit;

use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaSeoTextService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MediaSeoTextServiceTest extends TestCase
{
    public function testPublicTitlePrefersHumanTitle(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/IMG_1234.jpg')
            ->setTitle('Vue depuis la crête');

        self::assertSame('Vue depuis la crête', $this->service()->publicTitle($media, 'Randonnée du test'));
    }

    public function testPublicAltFallsBackToContextWhenAltLooksTechnical(): void
    {
        $department = (new Destination())
            ->setName('Pyrenees-Orientales')
            ->setSlug('pyrenees-orientales')
            ->setType(DestinationType::Department);
        $city = (new Destination())
            ->setName('Collioure')
            ->setSlug('collioure')
            ->setType(DestinationType::City)
            ->setParent($department);
        $place = (new Place())
            ->setName('Fort Saint-Elme')
            ->setSlug('fort-saint-elme')
            ->setDestination($city);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath('/uploads/media/IMG_1234.jpg')
            ->setAltText('IMG_1234.jpg');

        self::assertSame(
            'Panorama de Fort Saint-Elme à Collioure dans les Pyrenees-Orientales',
            $this->service()->publicAlt($media, $place),
        );
    }

    public function testFilenameBaseCombinesContextDestinationAndMediaType(): void
    {
        self::assertSame(
            'fort-saint-elme-collioure-panorama',
            $this->service()->filenameBaseForContext(
                (new Place())
                    ->setName('Fort Saint-Elme')
                    ->setSlug('fort-saint-elme')
                    ->setDestination((new Destination())
                        ->setName('Collioure')
                        ->setSlug('collioure')
                        ->setType(DestinationType::City)),
                MediaType::Image,
                ImageType::WideAngle,
            ),
        );
    }

    public function testTechnicalTextDetectionCatchesCameraFilenamesAndOptimizedNames(): void
    {
        $media = (new MediaAsset())
            ->setFilePath('/uploads/media/photo-original-abc123.jpg')
            ->setThumbnailPath('/uploads/media/thumbnail.jpg');
        $service = $this->service();

        self::assertTrue($service->isTechnicalText('IMG_1234.jpg', $media));
        self::assertTrue($service->isTechnicalText('photo-original-abc123', $media));
        self::assertFalse($service->isTechnicalText('Coucher de soleil', $media));
    }

    private function service(): MediaSeoTextService
    {
        return new MediaSeoTextService(new AsciiSlugger('fr'));
    }
}
