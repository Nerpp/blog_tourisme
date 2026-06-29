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
            'media_cover_image_url',
            'media_cover_image_srcset',
            'media_cover_image_dimensions',
            'media_display_image_url',
            'media_display_image_srcset',
            'media_display_image_dimensions',
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
        self::assertNull($extension->coverUrl(null));
        self::assertNull($extension->coverSrcset(null, 'webp'));
        self::assertNull($extension->coverDimensions(null));
        self::assertNull($extension->displayUrl(null));
        self::assertNull($extension->displaySrcset(null));
        self::assertNull($extension->displayDimensions(null));
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

    public function testStandardSecondaryProfilesPreferCompactVariantsAndKeepHighDensityCandidates(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/thumb.webp', 'width' => 600, 'height' => 450],
                'mobile' => ['webp' => '/uploads/media/mobile.webp', 'width' => 960, 'height' => 720],
                'medium' => ['webp' => '/uploads/media/medium.webp', 'width' => 1600, 'height' => 1200],
                'large' => ['webp' => '/uploads/media/large.webp', 'width' => 1920, 'height' => 1440],
                'thumbnail320' => ['webp' => '/uploads/media/thumbnail-320.webp', 'width' => 320, 'height' => 240],
                'thumbnail480' => ['webp' => '/uploads/media/thumbnail-480.webp', 'width' => 480, 'height' => 360],
                'content640' => ['webp' => '/uploads/media/content-640.webp', 'width' => 640, 'height' => 480],
                'content768' => ['webp' => '/uploads/media/content-768.webp', 'width' => 768, 'height' => 576],
                'content960' => ['webp' => '/uploads/media/content-960.webp', 'width' => 960, 'height' => 720],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/content-768.webp', $extension->displayUrl($media));
        self::assertSame('/uploads/media/thumbnail-480.webp', $extension->displayUrl($media, 'thumbnail'));
        self::assertSame(
            '/uploads/media/content-640.webp 640w, /uploads/media/content-768.webp 768w, /uploads/media/content-960.webp 960w, /uploads/media/medium.webp 1600w, /uploads/media/large.webp 1920w',
            $extension->displaySrcset($media),
        );
        self::assertSame(
            '/uploads/media/thumbnail-320.webp 320w, /uploads/media/thumbnail-480.webp 480w, /uploads/media/thumb.webp 600w, /uploads/media/mobile.webp 960w',
            $extension->displaySrcset($media, 'thumbnail'),
        );
        self::assertSame(['width' => 768, 'height' => 576], $extension->displayDimensions($media));
        self::assertSame(['width' => 480, 'height' => 360], $extension->displayDimensions($media, 'thumbnail'));
    }

    public function testPublicCoverUsesOnlyWebpDisplayCandidatesAndKeepsJpegFallback(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Degree360)
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/cover-640.webp', 'fallback' => '/uploads/media/cover-640.jpg', 'width' => 640, 'height' => 320],
                'mobile' => ['webp' => '/uploads/media/cover-960.webp', 'fallback' => '/uploads/media/cover-960.jpg', 'width' => 960, 'height' => 480],
                'medium' => ['webp' => '/uploads/media/cover-1280.webp', 'fallback' => '/uploads/media/cover-1280.jpg', 'width' => 1280, 'height' => 640],
                'large' => ['webp' => '/uploads/media/modal-2560.webp', 'fallback' => '/uploads/media/modal-2560.jpg', 'width' => 2560, 'height' => 1280],
            ]);
        $extension = $this->extension();

        self::assertSame('/uploads/media/cover-1280.webp', $extension->coverUrl($media));
        self::assertSame('/uploads/media/cover-1280.jpg', $extension->coverUrl($media, format: 'fallback'));
        self::assertSame(
            '/uploads/media/cover-640.webp 640w, /uploads/media/cover-960.webp 960w, /uploads/media/cover-1280.webp 1280w',
            $extension->coverSrcset($media, 'webp'),
        );
        self::assertSame(
            '/uploads/media/cover-640.jpg 640w, /uploads/media/cover-960.jpg 960w, /uploads/media/cover-1280.jpg 1280w',
            $extension->coverSrcset($media, 'fallback'),
        );
        self::assertSame(['width' => 1280, 'height' => 640], $extension->coverDimensions($media));
        self::assertStringNotContainsString('2560', (string) $extension->coverSrcset($media, 'webp'));
    }

    public function testPublicCoverKeepsAValidFallbackWhenHistoricalWebpOrSizeIsMissing(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath('/uploads/media/historical-panorama.jpg')
            ->setVariants([
                'thumb' => ['fallback' => '/uploads/media/historical-640.jpg', 'width' => 640, 'height' => 320],
                'medium' => ['fallback' => '/uploads/media/historical-1280.jpg', 'width' => 1280, 'height' => 640],
            ]);
        $extension = $this->extension();

        self::assertNull($extension->coverUrl($media, format: 'webp'));
        self::assertNull($extension->coverSrcset($media, 'webp'));
        self::assertSame('/uploads/media/historical-1280.jpg', $extension->coverUrl($media, format: 'fallback'));
        self::assertSame(
            '/uploads/media/historical-640.jpg 640w, /uploads/media/historical-1280.jpg 1280w',
            $extension->coverSrcset($media, 'fallback'),
        );
        self::assertSame(['width' => 1280, 'height' => 640], $extension->coverDimensions($media));
    }

    public function testResponsiveArticleSourceIsReservedForModal(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setMetadata(['articleResponsiveWebp' => true])
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/article-inline.webp', 'width' => 640, 'height' => 360],
                'mobile' => ['webp' => '/uploads/media/article-display.webp', 'width' => 960, 'height' => 540],
                'medium' => ['webp' => '/uploads/media/article-cover.webp', 'width' => 1280, 'height' => 720],
                'large' => ['webp' => '/uploads/media/article-source.webp', 'width' => 1600, 'height' => 900],
            ]);
        $extension = $this->extension();

        self::assertSame(
            '/uploads/media/article-inline.webp 640w, /uploads/media/article-display.webp 960w, /uploads/media/article-cover.webp 1280w',
            $extension->imageSrcset($media, 'webp'),
        );
        self::assertSame('/uploads/media/article-source.webp', $extension->modalUrl($media));
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
