<?php

namespace App\Tests\Unit\Media;

use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaSeoTextService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MediaSeoTextServiceSecurityTest extends TestCase
{
    public function testFilenameBaseTransliteratesAndAvoidsDangerousOriginalFilename(): void
    {
        $hike = (new HikeDraft())
            ->setTitle('Crête d’Été ../ dangereux')
            ->setSlug('crete-ete');

        $filename = $this->service()->filenameBaseForContext($hike, MediaType::Image, ImageType::Degree360);

        self::assertSame('crete-d-ete-dangereux-vue-360', $filename);
        self::assertStringNotContainsString('..', $filename);
        self::assertStringNotContainsString('/', $filename);
    }

    public function testFallbackTextNeverReturnsEmptyString(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/IMG_9999.jpg')
            ->setAltText('IMG_9999.jpg')
            ->setTitle('IMG_9999.jpg');

        self::assertSame('Photo de ce lieu', $this->service()->publicTitle($media));
        self::assertSame('Vue de ce lieu', $this->service()->publicAlt($media));
    }

    public function testHumanCaptionBeatsTechnicalTitle(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/DJI_0012.jpg')
            ->setTitle('DJI_0012.jpg')
            ->setCaption('Lumière sur la plage');

        self::assertSame('Lumière sur la plage', $this->service()->publicTitle($media, 'Fallback'));
    }

    private function service(): MediaSeoTextService
    {
        return new MediaSeoTextService(new AsciiSlugger('fr'));
    }
}
