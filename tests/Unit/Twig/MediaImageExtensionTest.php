<?php

namespace App\Tests\Unit\Twig;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaSeoTextService;
use App\Twig\MediaImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MediaImageExtensionTest extends TestCase
{
    public function testImageUrlPrefersRequestedVariantAndFallsBackToPlaceholderForStandardWithoutVariants(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/original.jpg')
            ->setThumbnailPath('/uploads/media/thumb.jpg')
            ->setVariants([
                'thumb' => ['fallback' => '/uploads/media/variants/thumb.jpg', 'width' => 100, 'height' => 50],
                'large' => ['fallback' => '/uploads/media/variants/large.jpg', 'width' => 400, 'height' => 200],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/variants/thumb.jpg', $extension->imageUrl($media, 'thumb'));
        self::assertSame('/uploads/media/variants/large.jpg', $extension->imageUrl($media, 'large'));
        self::assertSame('/images/placeholders/destination-card-placeholder.webp', $extension->imageUrl((new MediaAsset())->setMediaType(MediaType::Image)->setFilePath('/uploads/media/original.jpg'), 'large'));
        self::assertNull($extension->imageUrl(null));
    }

    public function testSpecialImageUrlCanStillFallBackToFilePath(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath('/uploads/media/panorama.jpg');

        self::assertSame('/uploads/media/panorama.jpg', $this->extension()->imageUrl($media, 'large'));
    }

    public function testModalUrlPrefersLargeWebpBeforeFallback(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/original.jpg')
            ->setVariants([
                'large' => [
                    'webp' => '/uploads/media/variants/large.webp',
                    'fallback' => '/uploads/media/variants/large.jpg',
                ],
            ]);

        self::assertSame('/uploads/media/variants/large.webp', $this->extension()->modalUrl($media));
    }

    public function testSrcsetDeduplicatesWidthsAndDimensionsUseVariantThenMetadataThenEntity(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setWidth(320)
            ->setHeight(160)
            ->setMetadata(['thumbnailWidth' => 120, 'thumbnailHeight' => 60])
            ->setVariants([
                'thumb' => ['fallback' => '/uploads/media/thumb.jpg', 'webp' => '/uploads/media/thumb.webp', 'width' => 120, 'height' => 60],
                'medium' => ['fallback' => '/uploads/media/medium.jpg', 'webp' => '/uploads/media/medium.webp', 'width' => 320, 'height' => 160],
                'large' => ['fallback' => '/uploads/media/large.jpg', 'webp' => '/uploads/media/large.webp', 'width' => 320, 'height' => 160],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/thumb.webp 120w, /uploads/media/medium.webp 320w', $extension->imageSrcset($media, 'webp'));
        self::assertSame(['width' => 120, 'height' => 60], $extension->imageDimensions($media));
        self::assertSame(['width' => 320, 'height' => 160], $extension->imageDimensions($media, 'unknown'));
    }

    public function testPosterUrlAndPublicTextFallbacks(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath('/uploads/media/poster.jpg')
            ->setVariants(['poster' => ['medium' => ['fallback' => '/uploads/media/poster-medium.jpg']]]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/poster-medium.jpg', $extension->posterUrl($media));
        self::assertSame('Fallback title', $extension->publicTitle(null, fallbackTitle: 'Fallback title'));
        self::assertSame('Fallback alt', $extension->publicAlt(null, fallbackTitle: 'Fallback alt'));
    }

    public function testCurrentBehaviorAllowsExternalUrlFallback(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setExternalUrl('https://cdn.example.test/photo.jpg');

        self::assertSame('https://cdn.example.test/photo.jpg', $this->extension()->imageUrl($media));
    }

    private function extension(): MediaImageExtension
    {
        return new MediaImageExtension(
            new Packages(new Package(new EmptyVersionStrategy())),
            new MediaSeoTextService(new AsciiSlugger('fr')),
        );
    }
}
