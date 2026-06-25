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
    public function testRegistersMediaTwigFunctions(): void
    {
        $functionNames = array_map(
            static fn (\Twig\TwigFunction $function): string => $function->getName(),
            $this->extension()->getFunctions(),
        );

        self::assertSame([
            'media_image_url',
            'media_modal_url',
            'media_poster_url',
            'media_image_srcset',
            'media_image_dimensions',
            'media_public_title',
            'media_public_alt',
        ], $functionNames);
    }

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

    public function testModalUrlFallsBackThroughSpecialImageExternalUrlThumbnailAndPlaceholder(): void
    {
        $extension = $this->extension();
        $specialImage = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Degree180)
            ->setFilePath('/uploads/media/180.jpg')
            ->setExternalUrl('https://cdn.example.test/180.jpg')
            ->setThumbnailPath('/uploads/media/180-thumb.jpg');
        $externalImage = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setExternalUrl('https://cdn.example.test/photo.jpg')
            ->setThumbnailPath('/uploads/media/photo-thumb.jpg');
        $thumbnailOnly = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath('/uploads/media/video-thumb.jpg');

        self::assertSame('/uploads/media/180.jpg', $extension->modalUrl($specialImage));
        self::assertSame('https://cdn.example.test/photo.jpg', $extension->modalUrl($externalImage));
        self::assertSame('/uploads/media/video-thumb.jpg', $extension->modalUrl($thumbnailOnly));
        self::assertSame('/images/placeholders/destination-card-placeholder.webp', $extension->modalUrl(new MediaAsset()));
    }

    public function testPosterUrlReturnsNullWhenNoPosterOrThumbnailExists(): void
    {
        self::assertNull($this->extension()->posterUrl(new MediaAsset()));
    }

    public function testSrcsetDeduplicatesWidthsAndDimensionsUseVariantThenMetadataThenEntity(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setWidth(320)
            ->setHeight(160)
            ->setMetadata(['thumbnailWidth' => 120, 'thumbnailHeight' => 60])
            ->setVariants([
                'thumb' => ['fallback' => '/uploads/media/thumb.jpg', 'webp' => '/uploads/media/thumb.webp', 'width' => 120, 'height' => 60],
                'mobile' => ['webp' => '/uploads/media/mobile.webp', 'width' => 240, 'height' => 120],
                'medium' => ['fallback' => '/uploads/media/medium.jpg', 'webp' => '/uploads/media/medium.webp', 'width' => 320, 'height' => 160],
                'large' => ['fallback' => '/uploads/media/large.jpg', 'webp' => '/uploads/media/large.webp', 'width' => 320, 'height' => 160],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/thumb.webp 120w, /uploads/media/mobile.webp 240w, /uploads/media/medium.webp 320w', $extension->imageSrcset($media, 'webp'));
        self::assertNull($extension->imageSrcset($media, 'fallback'));
        self::assertSame(['width' => 120, 'height' => 60], $extension->imageDimensions($media));
        self::assertSame(['width' => 320, 'height' => 160], $extension->imageDimensions($media, 'unknown'));
    }

    public function testDimensionsReturnNullWithoutNumericData(): void
    {
        $media = (new MediaAsset())
            ->setMetadata(['thumbnailWidth' => 'large', 'thumbnailHeight' => null]);

        self::assertNull($this->extension()->imageDimensions($media));
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

    public function testNullableMediaHelpersReturnNullWithoutMedia(): void
    {
        $extension = $this->extension();

        self::assertNull($extension->modalUrl(null));
        self::assertNull($extension->posterUrl(null));
        self::assertNull($extension->imageSrcset(null, 'webp'));
        self::assertNull($extension->imageDimensions(null));
    }

    public function testSrcsetSkipsIncompleteVariantsAndPosterFallsBackToThumbnail(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setThumbnailPath('/uploads/media/poster-fallback.jpg')
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/thumb.webp'],
                'medium' => ['width' => 320],
                'large' => ['webp' => '/uploads/media/large.webp', 'width' => '640'],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/large.webp 640w', $extension->imageSrcset($media, 'webp'));
        self::assertSame('/uploads/media/poster-fallback.jpg', $extension->posterUrl($media));
    }

    public function testStandardImageUrlAndModalUseWebpWithoutFallback(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/original.jpg')
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/thumb.webp', 'width' => 600, 'height' => 400],
                'mobile' => ['webp' => '/uploads/media/mobile.webp', 'width' => 960, 'height' => 640],
                'medium' => ['webp' => '/uploads/media/medium.webp', 'width' => 1600, 'height' => 1067],
                'large' => ['webp' => '/uploads/media/large.webp', 'width' => 1920, 'height' => 1280],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/mobile.webp', $extension->imageUrl($media, 'mobile'));
        self::assertSame('/uploads/media/large.webp', $extension->modalUrl($media));
        self::assertSame(
            '/uploads/media/thumb.webp 600w, /uploads/media/mobile.webp 960w, /uploads/media/medium.webp 1600w, /uploads/media/large.webp 1920w',
            $extension->imageSrcset($media, 'webp'),
        );
        self::assertNull($extension->imageSrcset($media, 'avif'));
    }

    public function testMalformedVariantDataUsesExistingFallbacks(): void
    {
        $extension = $this->extension();
        $image = (new MediaAsset())
            ->setThumbnailPath('/uploads/media/thumb-fallback.jpg')
            ->setVariants([
                'thumb' => ['fallback' => ['unexpected']],
                'large' => 'unexpected',
            ]);
        $video = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath('/uploads/media/poster-fallback.jpg')
            ->setVariants(['poster' => ['medium' => ['fallback' => '   ']]]);

        self::assertSame('/uploads/media/thumb-fallback.jpg', $extension->imageUrl($image));
        self::assertSame('/uploads/media/thumb-fallback.jpg', $extension->modalUrl($image));
        self::assertSame('/uploads/media/poster-fallback.jpg', $extension->posterUrl($video));
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
