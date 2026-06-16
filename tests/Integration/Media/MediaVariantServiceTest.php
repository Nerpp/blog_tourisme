<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaVariantService;
use App\Service\Media\PublicMediaMasterCleanupService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;

final class MediaVariantServiceTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testGeneratesVariantsAndUpdatesImageMediaAsset(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($source));

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame(['status' => 'generated', 'generated' => true, 'message' => null], $result);
        self::assertSame('image/jpeg', $media->getMimeType());
        self::assertSame(120, $media->getWidth());
        self::assertSame(60, $media->getHeight());
        self::assertIsArray($media->getVariants());
        self::assertStringStartsWith('/uploads/media/variants/', (string) $media->getThumbnailPath());
        $this->trackVariantFiles($media->getVariants());
    }

    public function testSkipsWhenUsableVariantsAlreadyExistUnlessForced(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 40, 20);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($source))
            ->setVariants([
                'thumb' => ['fallback' => '/uploads/media/existing-thumb.png', 'width' => 40, 'height' => 20],
                'medium' => ['fallback' => '/uploads/media/existing-medium.png', 'width' => 40, 'height' => 20],
                'large' => ['fallback' => '/uploads/media/existing-large.png', 'width' => 40, 'height' => 20],
            ]);

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame('skipped', $result['status']);
        self::assertFalse($result['generated']);
    }

    public function testMissingImageSourceReturnsErrorAndExternalVideoIsSkipped(): void
    {
        $missing = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/missing-media-variant.jpg');
        $externalVideo = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setExternalUrl('https://example.test/video');

        self::assertSame('error', $this->mediaVariantService()->generateForMedia($missing)['status']);
        self::assertSame('skipped', $this->mediaVariantService()->generateForMedia($externalVideo)['status']);
    }

    public function testGeneratesPosterVariantsForVideoWithLocalThumbnail(): void
    {
        $poster = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 100, 50);
        $this->files[] = $poster;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath(TestImageFactory::publicPathFor($poster));

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame('generated', $result['status']);
        self::assertIsArray($media->getVariants());
        self::assertArrayHasKey('poster', $media->getVariants());
        $this->trackVariantFiles($media->getVariants());
    }

    public function testCleanupDeletesClassicPublicMasterOnlyAfterVariantsExist(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));

        $this->mediaVariantService()->generateForMedia($media);
        $this->trackVariantFiles($media->getVariants());

        $result = $this->publicMediaMasterCleanupService()->cleanupIfSafe($media);

        self::assertTrue($result['deleted']);
        self::assertFalse(is_file($source));
        self::assertNull($media->getFilePath());
        self::assertIsArray($media->getMetadata());
        self::assertSame(TestImageFactory::publicPathFor($source), $media->getMetadata()['deletedPublicMasterPath']);
    }

    public function testCleanupSkipsSpecialImageTypes(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath(TestImageFactory::publicPathFor($source));

        $this->mediaVariantService()->generateForMedia($media);
        $this->trackVariantFiles($media->getVariants());

        $result = $this->publicMediaMasterCleanupService()->cleanupIfSafe($media);

        self::assertTrue($result['skipped']);
        self::assertSame('média non standard', $result['reason']);
        self::assertFileExists($source);
        self::assertSame(TestImageFactory::publicPathFor($source), $media->getFilePath());
    }

    /** @param array<string, mixed>|null $variants */
    private function trackVariantFiles(?array $variants): void
    {
        if (!is_array($variants)) {
            return;
        }

        array_walk_recursive($variants, function (mixed $value): void {
            if (!is_string($value) || !str_starts_with($value, '/uploads/media/')) {
                return;
            }

            $file = TestImageFactory::projectDir().'/public/'.ltrim($value, '/');
            if (is_file($file)) {
                $this->files[] = $file;
            }
        });
    }

    private function mediaVariantService(): MediaVariantService
    {
        $service = $this->service(MediaVariantService::class);
        self::assertInstanceOf(MediaVariantService::class, $service);

        return $service;
    }

    private function publicMediaMasterCleanupService(): PublicMediaMasterCleanupService
    {
        $service = $this->service(PublicMediaMasterCleanupService::class);
        self::assertInstanceOf(PublicMediaMasterCleanupService::class, $service);

        return $service;
    }
}
