<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaVariantService;
use App\Service\Media\PublicMediaMasterCleanupService;
use App\Service\Media\StandardLegacyVariantCleanupService;
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
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame(['status' => 'generated', 'generated' => true, 'message' => null], $result);
        self::assertSame('image/jpeg', $media->getMimeType());
        self::assertSame(120, $media->getWidth());
        self::assertSame(60, $media->getHeight());
        self::assertIsArray($media->getVariants());
        self::assertSame(['webp'], $media->getVariants()['source']['formats']);
        foreach (['thumb', 'mobile', 'medium', 'large', 'thumbnail320', 'thumbnail480', 'content640', 'content768', 'content960'] as $size) {
            self::assertArrayHasKey($size, $media->getVariants());
            self::assertArrayHasKey('webp', $media->getVariants()[$size]);
            self::assertArrayNotHasKey('fallback', $media->getVariants()[$size]);
            self::assertArrayNotHasKey('avif', $media->getVariants()[$size]);
        }
        self::assertStringStartsWith('/uploads/media/variants/', (string) $media->getThumbnailPath());
        self::assertStringEndsWith('.webp', (string) $media->getThumbnailPath());
        $this->trackVariantFiles($media->getVariants());
    }

    public function testGeneratesSecondaryVariantsFromRetainedLargeWebpWithoutChangingCoreVariants(): void
    {
        $retained = TestImageFactory::createWebp(TestImageFactory::publicMediaDirectory().'/variants', 1920, 960);
        $this->files[] = $retained;
        $retainedPath = '/uploads/media/variants/'.basename($retained);
        $coreVariants = [
            'source' => ['path' => '/uploads/media/deleted-master.jpg', 'width' => 2400, 'height' => 1200],
            'thumb' => ['webp' => '/uploads/media/variants/original-thumb.webp', 'width' => 600, 'height' => 300],
            'mobile' => ['webp' => '/uploads/media/variants/original-mobile.webp', 'width' => 960, 'height' => 480],
            'medium' => ['webp' => '/uploads/media/variants/original-medium.webp', 'width' => 1600, 'height' => 800],
            'large' => ['webp' => $retainedPath, 'width' => 1920, 'height' => 960],
        ];
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(null)
            ->setVariants($coreVariants);

        $service = $this->mediaVariantService();

        self::assertTrue($service->supports($media));
        self::assertFalse($service->hasUsableVariants($media));
        self::assertSame('generated', $service->generateForMedia($media)['status']);
        foreach ($coreVariants as $name => $variant) {
            self::assertSame($variant, $media->getVariants()[$name]);
        }
        foreach (['thumbnail320', 'thumbnail480', 'content640', 'content768', 'content960'] as $size) {
            self::assertArrayHasKey($size, $media->getVariants());
            self::assertArrayHasKey('webp', $media->getVariants()[$size]);
        }
        self::assertTrue($service->hasUsableVariants($media));
        $this->trackVariantFiles($media->getVariants());
    }

    public function testLegacySpecialImageGenerationPreservesExistingThumbnailPath(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath(TestImageFactory::publicPathFor($source))
            ->setThumbnailPath('/uploads/media/manual-thumbnail.jpg');

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame('generated', $result['status']);
        self::assertSame('/uploads/media/manual-thumbnail.jpg', $media->getThumbnailPath());
        $this->trackVariantFiles($media->getVariants());
    }

    public function testLegacyArticleSingleWebpIsNotAutomaticallyModifiedEvenWhenForced(): void
    {
        $source = TestImageFactory::createWebp(TestImageFactory::publicMediaDirectory(), 1600, 900, 'article-single-service.webp');
        $this->files[] = $source;
        $publicPath = TestImageFactory::publicPathFor($source);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($publicPath)
            ->setThumbnailPath($publicPath)
            ->setMimeType('image/webp')
            ->setWidth(1600)
            ->setHeight(900)
            ->setMetadata(['articleOptimizedSingleWebp' => true]);

        $service = $this->mediaVariantService();

        self::assertTrue($service->hasUsableVariants($media));
        self::assertSame([
            'status' => 'skipped',
            'generated' => false,
            'message' => 'Média Article déjà géré par son pipeline WebP dédié.',
        ], $service->generateForMedia($media, force: true));
        self::assertNull($media->getVariants());
        self::assertSame($publicPath, $media->getFilePath());
    }

    public function testResponsiveArticleWebpsAreNotRegeneratedByTheSharedPipeline(): void
    {
        $source = TestImageFactory::createWebp(TestImageFactory::publicMediaDirectory(), 1600, 900, 'article-responsive-service.webp');
        $this->files[] = $source;
        $publicPath = TestImageFactory::publicPathFor($source);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($publicPath)
            ->setThumbnailPath('/uploads/media/article-inline.webp')
            ->setMimeType('image/webp')
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/article-inline.webp', 'width' => 640, 'height' => 360],
                'mobile' => ['webp' => '/uploads/media/article-display.webp', 'width' => 960, 'height' => 540],
                'medium' => ['webp' => '/uploads/media/article-cover.webp', 'width' => 1280, 'height' => 720],
                'large' => ['webp' => $publicPath, 'width' => 1600, 'height' => 900],
            ])
            ->setMetadata(['articleResponsiveWebp' => true]);

        $result = $this->mediaVariantService()->generateForMedia($media, force: true);

        self::assertSame('skipped', $result['status']);
        self::assertFalse($result['generated']);
        self::assertSame($publicPath, $media->getFilePath());
        self::assertSame(640, $media->getVariants()['thumb']['width'] ?? null);

        $masterCleanup = $this->publicMediaMasterCleanupService()->cleanupIfSafe($media);
        self::assertTrue($masterCleanup['skipped']);
        self::assertSame('source WebP Article conservée pour le lightbox', $masterCleanup['reason']);
        self::assertFileExists($source);

        $variantCleanup = $this->standardLegacyVariantCleanupService()->cleanup($media, pruneMetadata: true);
        self::assertTrue($variantCleanup['skipped']);
        self::assertSame('variantes Article gérées par leur pipeline dédié', $variantCleanup['reason']);
        self::assertSame(640, $media->getVariants()['thumb']['width'] ?? null);
    }

    public function testReportsSupportedOutputFormatsAndMediaSupportRules(): void
    {
        $service = $this->mediaVariantService();
        $formats = $service->supportedOutputFormats();

        self::assertContains('fallback', $formats);
        self::assertSame(in_array('webp', $formats, true), $service->supportsWebp());
        self::assertSame(in_array('avif', $formats, true), $service->supportsAvif());
        self::assertSame(['webp'], $service->standardOutputFormats());
        self::assertTrue($service->supports(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setMimeType('image/png')
                ->setFilePath('/uploads/media/photo.png'),
        ));
        self::assertFalse($service->supports(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setMimeType('image/svg+xml')
                ->setFilePath('/uploads/media/photo.svg'),
        ));
        self::assertFalse($service->supports(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setFilePath('https://example.test/photo.jpg'),
        ));
        self::assertTrue($service->supports(
            (new MediaAsset())
                ->setMediaType(MediaType::Video)
                ->setThumbnailPath('/uploads/media/poster.jpg'),
        ));
        self::assertFalse($service->supports(
            (new MediaAsset())
                ->setMediaType(MediaType::Video)
                ->setThumbnailPath('https://example.test/poster.jpg'),
        ));
    }

    public function testDetectsUsableImageAndVideoVariants(): void
    {
        $service = $this->mediaVariantService();

        self::assertTrue($service->hasUsableVariants(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setImageType(ImageType::Standard)
                ->setVariants([
                    'thumb' => ['webp' => '/uploads/media/thumb.webp'],
                    'mobile' => ['webp' => '/uploads/media/mobile.webp'],
                    'medium' => ['webp' => '/uploads/media/medium.webp'],
                    'large' => ['webp' => '/uploads/media/large.webp'],
                    'thumbnail320' => ['webp' => '/uploads/media/thumbnail-320.webp'],
                    'thumbnail480' => ['webp' => '/uploads/media/thumbnail-480.webp'],
                    'content640' => ['webp' => '/uploads/media/content-640.webp'],
                    'content768' => ['webp' => '/uploads/media/content-768.webp'],
                    'content960' => ['webp' => '/uploads/media/content-960.webp'],
                ]),
        ));
        self::assertFalse($service->hasUsableVariants(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setImageType(ImageType::Standard)
                ->setVariants(['thumb' => ['webp' => '/uploads/media/thumb.webp']]),
        ));
        self::assertTrue($service->hasUsableVariants(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setImageType(ImageType::Degree180)
                ->setVariants([
                    'thumb' => ['fallback' => '/uploads/media/thumb.jpg'],
                    'mobile' => ['fallback' => '/uploads/media/mobile.jpg'],
                    'medium' => ['fallback' => '/uploads/media/medium.jpg'],
                    'large' => ['fallback' => '/uploads/media/large.jpg'],
                ]),
        ));
        self::assertFalse($service->hasUsableVariants(
            (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setImageType(ImageType::Degree180)
                ->setVariants([
                    'thumb' => ['fallback' => '/uploads/media/thumb.jpg'],
                    'medium' => ['fallback' => '/uploads/media/medium.jpg'],
                    'large' => ['fallback' => '/uploads/media/large.jpg'],
                ]),
        ));
        self::assertTrue($service->hasUsableVariants(
            (new MediaAsset())
                ->setMediaType(MediaType::Video)
                ->setVariants(['poster' => ['fallback' => '/uploads/media/poster.jpg']]),
        ));
        self::assertFalse($service->hasUsableVariants(
            (new MediaAsset())
                ->setMediaType(MediaType::Video)
                ->setVariants(['thumb' => ['fallback' => '/uploads/media/thumb.jpg']]),
        ));
    }

    public function testSkipsWhenUsableVariantsAlreadyExistUnlessForced(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 40, 20);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source))
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'mobile' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'medium' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'large' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'thumbnail320' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'thumbnail480' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'content640' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'content768' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'content960' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
            ]);

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame('skipped', $result['status']);
        self::assertFalse($result['generated']);
    }

    public function testForceRegeneratesImageVariantsEvenWhenUsableVariantsExist(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 40, 20);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source))
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'mobile' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'medium' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
                'large' => ['webp' => '/uploads/media/existing-thumb.webp', 'width' => 40, 'height' => 20],
            ]);

        $result = $this->mediaVariantService()->generateForMedia($media, force: true);

        self::assertSame('generated', $result['status']);
        self::assertTrue($result['generated']);
        self::assertSame('image/png', $media->getMimeType());
        self::assertSame(40, $media->getWidth());
        self::assertSame(20, $media->getHeight());
        self::assertIsArray($media->getVariants());
        self::assertStringStartsWith('/uploads/media/variants/', (string) $media->getThumbnailPath());
        $this->trackVariantFiles($media->getVariants());
    }

    public function testStandardRegenerationDeletesOnlyLegacyNonWebpVariantFiles(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 120, 60);
        $legacyJpeg = TestImageFactory::createJpeg($this->publicVariantDirectory(), 40, 20, 'legacy-standard-thumb.jpg');
        $legacyPng = TestImageFactory::createPng($this->publicVariantDirectory(), 40, 20, 'legacy-standard-medium.png');
        $legacyAvif = TestImageFactory::createTextFile($this->publicVariantDirectory(), 'avif', 'legacy avif');
        $legacyWebp = TestImageFactory::createWebp($this->publicVariantDirectory(), 40, 20, 'legacy-standard-kept.webp');
        array_push($this->files, $source, $legacyJpeg, $legacyPng, $legacyAvif, $legacyWebp);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source))
            ->setVariants([
                'thumb' => [
                    'fallback' => $this->publicVariantPath($legacyJpeg),
                    'webp' => $this->publicVariantPath($legacyWebp),
                    'width' => 40,
                    'height' => 20,
                ],
                'mobile' => ['webp' => $this->publicVariantPath($legacyWebp), 'width' => 40, 'height' => 20],
                'medium' => [
                    'fallback' => $this->publicVariantPath($legacyPng),
                    'webp' => $this->publicVariantPath($legacyWebp),
                    'width' => 40,
                    'height' => 20,
                ],
                'large' => [
                    'avif' => $this->publicVariantPath($legacyAvif),
                    'webp' => $this->publicVariantPath($legacyWebp),
                    'width' => 40,
                    'height' => 20,
                ],
            ]);

        $result = $this->mediaVariantService()->generateForMedia($media, force: true);

        self::assertSame('generated', $result['status']);
        self::assertFileDoesNotExist($legacyJpeg);
        self::assertFileDoesNotExist($legacyPng);
        self::assertFileDoesNotExist($legacyAvif);
        self::assertFileExists($legacyWebp);
        self::assertIsArray($media->getVariants());
        foreach (['thumb', 'mobile', 'medium', 'large'] as $size) {
            self::assertSame(['webp', 'width', 'height'], array_keys($media->getVariants()[$size]));
            self::assertStringEndsWith('.webp', $media->getVariants()[$size]['webp']);
        }
        $this->trackVariantFiles($media->getVariants());
    }

    public function testLegacyVariantCleanupDryRunKeepsFilesAndSkipsSpecialMedia(): void
    {
        $activeWebp = TestImageFactory::createWebp($this->publicVariantDirectory(), 120, 60, 'active-standard-thumb.webp');
        $legacyJpeg = TestImageFactory::createJpeg($this->publicVariantDirectory(), 40, 20, 'dry-run-legacy-thumb.jpg');
        array_push($this->files, $activeWebp, $legacyJpeg);
        $variants = [
            'source' => ['path' => '/uploads/media/source.jpg', 'formats' => ['fallback', 'webp']],
            'thumb' => [
                'fallback' => $this->publicVariantPath($legacyJpeg),
                'webp' => $this->publicVariantPath($activeWebp),
                'width' => 120,
                'height' => 60,
            ],
            'mobile' => ['webp' => $this->publicVariantPath($activeWebp), 'width' => 120, 'height' => 60],
            'medium' => ['webp' => $this->publicVariantPath($activeWebp), 'width' => 120, 'height' => 60],
            'large' => ['webp' => $this->publicVariantPath($activeWebp), 'width' => 120, 'height' => 60],
        ];
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setVariants($variants);

        $result = $this->standardLegacyVariantCleanupService()->cleanup($media, dryRun: true, pruneMetadata: true);

        self::assertFalse($result['skipped']);
        self::assertTrue($result['dryRun']);
        self::assertTrue($result['metadataChanged']);
        self::assertSame($this->publicVariantPath($legacyJpeg), $result['files'][0]['path']);
        self::assertGreaterThan(0, $result['bytes']);
        self::assertFileExists($legacyJpeg);
        self::assertSame($variants, $media->getVariants());

        $special = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setVariants($variants);
        $specialResult = $this->standardLegacyVariantCleanupService()->cleanup($special);

        self::assertTrue($specialResult['skipped']);
        self::assertSame('média non standard', $specialResult['reason']);
        self::assertFileExists($legacyJpeg);
    }

    public function testLegacyCleanupReportsInvalidActiveAndLegacyVariantPaths(): void
    {
        $cleanup = $this->standardLegacyVariantCleanupService();
        $withoutVariants = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard);
        self::assertSame(
            'variante WebP active thumb absente',
            $cleanup->cleanup($withoutVariants)['reason'],
        );

        $invalidWebpPath = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setVariants(['thumb' => ['webp' => '/uploads/media/variants/thumb.jpg']]);
        self::assertSame(
            'chemin WebP actif thumb absent',
            $cleanup->cleanup($invalidWebpPath)['reason'],
        );

        $missingActiveFile = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setVariants(['thumb' => ['webp' => '/uploads/media/variants/missing-active.webp']]);
        self::assertSame(
            'fichier WebP actif thumb absent ou illisible',
            $cleanup->cleanup($missingActiveFile)['reason'],
        );

        $activeWebp = TestImageFactory::createWebp($this->publicVariantDirectory(), 40, 20, 'cleanup-active.webp');
        $this->files[] = $activeWebp;
        $activePath = $this->publicVariantPath($activeWebp);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setVariants([
                'thumb' => ['webp' => $activePath, 'width' => new \stdClass(), 'height' => 20],
                'mobile' => ['webp' => $activePath, 'width' => 40, 'height' => 20],
                'medium' => ['webp' => $activePath, 'width' => 40, 'height' => 20],
                'large' => ['webp' => $activePath, 'width' => 40, 'height' => 20],
            ]);

        $result = $cleanup->cleanup($media, dryRun: true, legacyVariants: [
            'unsafe' => ['fallback' => '/uploads/media/variants/unsafe\\legacy.jpg'],
            'missing' => ['fallback' => '/uploads/media/variants/missing-legacy.jpg'],
            'nested' => ['fallback' => '/uploads/media/variants/nested/legacy.jpg'],
        ], pruneMetadata: true);

        self::assertFalse($result['skipped']);
        self::assertSame('certains fichiers n’ont pas pu être supprimés', $result['reason']);
        self::assertTrue($result['metadataChanged']);
        self::assertCount(2, $result['files']);
        self::assertSame('chemin ignoré', $result['files'][0]['reason']);
        self::assertTrue($result['files'][1]['missing']);
        self::assertSame('fichier absent', $result['files'][1]['reason']);
    }

    public function testMissingLocalVideoPosterIsSkippedWithDiagnostic(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath('/uploads/media/missing-video-poster.jpg');

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame('skipped', $result['status']);
        self::assertFalse($result['generated']);
        self::assertStringContainsString('Vidéo externe avec poster local manquant.', (string) $result['message']);
    }

    public function testForceRegeneratesVideoPosterVariantsAndKeepsOtherVariantGroups(): void
    {
        $poster = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 90, 45);
        $this->files[] = $poster;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath(TestImageFactory::publicPathFor($poster))
            ->setVariants([
                'poster' => ['fallback' => '/uploads/media/posters/existing-poster.jpg'],
                'legacy' => ['fallback' => '/uploads/media/legacy.jpg'],
            ]);

        $skipped = $this->mediaVariantService()->generateForMedia($media);
        $generated = $this->mediaVariantService()->generateForMedia($media, force: true);

        self::assertSame('skipped', $skipped['status']);
        self::assertSame('generated', $generated['status']);
        self::assertIsArray($media->getVariants());
        self::assertArrayHasKey('poster', $media->getVariants());
        self::assertArrayHasKey('legacy', $media->getVariants());
        $this->trackVariantFiles($media->getVariants());
    }

    public function testPosterGenerationFiltersInvalidVariantEntriesAndPreservesValidOnes(): void
    {
        $poster = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 90, 45);
        $this->files[] = $poster;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath(TestImageFactory::publicPathFor($poster));
        $variantsProperty = new \ReflectionProperty($media, 'variants');
        $variantsProperty->setValue($media, [
            'legacy' => [
                'fallback' => '/uploads/media/legacy.jpg',
                'width' => 90,
                'formats' => ['fallback', 'webp'],
                'optimized' => false,
                'invalidObject' => new \stdClass(),
                'details' => [
                    'quality' => 'high',
                    'invalidNestedObject' => new \stdClass(),
                ],
            ],
            'invalidTopLevelObject' => new \stdClass(),
        ]);

        $result = $this->mediaVariantService()->generateForMedia($media, force: true);
        $variants = $media->getVariants();

        self::assertSame('generated', $result['status']);
        self::assertIsArray($variants);
        self::assertArrayHasKey('poster', $variants);
        self::assertSame([
            'fallback' => '/uploads/media/legacy.jpg',
            'width' => 90,
            'formats' => ['fallback', 'webp'],
            'optimized' => false,
            'details' => ['quality' => 'high'],
        ], $variants['legacy']);
        self::assertArrayNotHasKey('invalidTopLevelObject', $variants);
        $this->trackVariantFiles($variants);
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

    public function testCorruptLocalImageReturnsControlledErrorWithoutVariants(): void
    {
        $source = TestImageFactory::createTextFile(
            TestImageFactory::publicMediaDirectory(),
            'jpg',
            'not a real image',
        );
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setMimeType('image/jpeg')
            ->setFilePath(TestImageFactory::publicPathFor($source));

        $result = $this->mediaVariantService()->generateForMedia($media);

        self::assertSame('error', $result['status']);
        self::assertFalse($result['generated']);
        self::assertStringContainsString('image source est illisible', (string) $result['message']);
        self::assertNull($media->getVariants());
        self::assertFileExists($source);
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

    public function testCleanupDryRunKeepsClassicPublicMasterAfterVariantValidation(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));

        $this->mediaVariantService()->generateForMedia($media);
        $this->trackVariantFiles($media->getVariants());

        $cleanupService = $this->publicMediaMasterCleanupService();
        self::assertSame(['valid' => true, 'reason' => null], $cleanupService->validateCriticalVariants($media));

        $result = $cleanupService->cleanupIfSafe($media, dryRun: true);

        self::assertFalse($result['deleted']);
        self::assertFalse($result['skipped']);
        self::assertTrue($result['dryRun']);
        self::assertFileExists($source);
        self::assertSame(TestImageFactory::publicPathFor($source), $media->getFilePath());
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

        $secondResult = $this->publicMediaMasterCleanupService()->cleanupIfSafe($media);

        self::assertFalse($secondResult['deleted']);
        self::assertTrue($secondResult['skipped']);
        self::assertSame('chemin maître absent ou non supprimable', $secondResult['reason']);
    }

    public function testCleanupSkipsAlreadyMissingMasterAfterValidVariantValidation(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $publicPath = TestImageFactory::publicPathFor($source);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($publicPath);

        $this->mediaVariantService()->generateForMedia($media);
        $this->trackVariantFiles($media->getVariants());
        unlink($source);

        $result = $this->publicMediaMasterCleanupService()->cleanupIfSafe($media);

        self::assertFalse($result['deleted']);
        self::assertTrue($result['skipped']);
        self::assertSame('fichier maître déjà absent', $result['reason']);
        self::assertSame($publicPath, $media->getFilePath());
        self::assertNull($media->getMetadata());
    }

    public function testCleanupSkipsEverySpecialImageTypeAndKeepsItsResponsiveCoverVariants(): void
    {
        foreach ([ImageType::Degree360, ImageType::Degree180, ImageType::Panorama, ImageType::WideAngle] as $imageType) {
            $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
            $this->files[] = $source;
            $media = (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setImageType($imageType)
                ->setFilePath(TestImageFactory::publicPathFor($source));

            $this->mediaVariantService()->generateForMedia($media);
            $this->trackVariantFiles($media->getVariants());
            $variants = $media->getVariants();
            self::assertIsArray($variants);
            self::assertArrayHasKey('fallback', $variants['thumb']);
            self::assertArrayHasKey('mobile', $variants);
            self::assertArrayHasKey('fallback', $variants['mobile']);

            $result = $this->publicMediaMasterCleanupService()->cleanupIfSafe($media);

            self::assertTrue($result['skipped']);
            self::assertSame('média non standard', $result['reason']);
            self::assertFileExists($source);
            self::assertSame(TestImageFactory::publicPathFor($source), $media->getFilePath());
        }
    }

    public function testCleanupSkipsUnsafeMasterPathsAndMissingVariants(): void
    {
        $cleanupService = $this->publicMediaMasterCleanupService();

        $withoutPath = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard);
        $externalPath = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('https://example.test/photo.jpg');
        $nestedPath = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/nested/photo.jpg');
        $withoutVariants = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/photo.jpg');
        $missingWebp = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/missing-thumb.webp'],
                'mobile' => ['webp' => '/uploads/media/missing-mobile.webp'],
                'medium' => ['webp' => '/uploads/media/missing-medium.webp'],
                'large' => ['webp' => '/uploads/media/missing-large.webp'],
            ]);

        self::assertTrue($cleanupService->isClassicImage($withoutPath));
        self::assertFalse($cleanupService->isClassicImage((new MediaAsset())->setMediaType(MediaType::Video)));
        self::assertSame('chemin maître absent ou non supprimable', $cleanupService->cleanupIfSafe($withoutPath)['reason']);
        self::assertSame('chemin maître absent ou non supprimable', $cleanupService->cleanupIfSafe($externalPath)['reason']);
        self::assertSame('chemin maître absent ou non supprimable', $cleanupService->cleanupIfSafe($nestedPath)['reason']);
        self::assertSame('aucune variante enregistrée', $cleanupService->cleanupIfSafe($withoutVariants)['reason']);
        self::assertSame('WebP thumb absent ou illisible', $cleanupService->validateCriticalVariants($missingWebp)['reason']);
    }

    public function testCleanupReportsSpecificMissingCriticalVariantReasons(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));
        $this->mediaVariantService()->generateForMedia($media);
        $this->trackVariantFiles($media->getVariants());
        $variants = $media->getVariants();
        self::assertIsArray($variants);
        unset($variants['medium']);
        $media->setVariants($variants);

        $missingSize = $this->publicMediaMasterCleanupService()->validateCriticalVariants($media);
        self::assertSame(['valid' => false, 'reason' => 'variante medium absente'], $missingSize);

        $media->setVariants([
            'thumb' => ['webp' => TestImageFactory::publicPathFor($source)],
            'mobile' => ['webp' => TestImageFactory::publicPathFor($source)],
            'medium' => ['webp' => TestImageFactory::publicPathFor($source)],
            'large' => ['webp' => TestImageFactory::publicPathFor($source)],
        ]);

        $validation = $this->publicMediaMasterCleanupService()->validateCriticalVariants($media);
        self::assertSame(['valid' => false, 'reason' => 'WebP thumb absent ou illisible'], $validation);
    }

    public function testMasterCleanupRejectsNonMediaAndMalformedVariantPaths(): void
    {
        $cleanup = $this->publicMediaMasterCleanupService();
        $outsideMediaDirectory = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/other/master.jpg');
        self::assertSame(
            'chemin maître absent ou non supprimable',
            $cleanup->cleanupIfSafe($outsideMediaDirectory)['reason'],
        );

        $nonStringVariant = (new MediaAsset())->setVariants([
            'thumb' => ['webp' => 42],
            'mobile' => ['webp' => '/uploads/media/variants/mobile.webp'],
            'medium' => ['webp' => '/uploads/media/variants/medium.webp'],
            'large' => ['webp' => '/uploads/media/variants/large.webp'],
        ]);
        self::assertSame(
            'WebP thumb absent ou illisible',
            $cleanup->validateCriticalVariants($nonStringVariant)['reason'],
        );

        $externalVariant = (new MediaAsset())->setVariants([
            'thumb' => ['webp' => 'https://example.test/thumb.webp'],
            'mobile' => ['webp' => '/uploads/media/variants/mobile.webp'],
            'medium' => ['webp' => '/uploads/media/variants/medium.webp'],
            'large' => ['webp' => '/uploads/media/variants/large.webp'],
        ]);
        self::assertSame(
            'WebP thumb absent ou illisible',
            $cleanup->validateCriticalVariants($externalVariant)['reason'],
        );
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

    private function standardLegacyVariantCleanupService(): StandardLegacyVariantCleanupService
    {
        $service = $this->service(StandardLegacyVariantCleanupService::class);
        self::assertInstanceOf(StandardLegacyVariantCleanupService::class, $service);

        return $service;
    }

    private function publicVariantDirectory(): string
    {
        $directory = TestImageFactory::projectDir().'/public/uploads/media/variants';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function publicVariantPath(string $absolutePath): string
    {
        return '/uploads/media/variants/'.basename($absolutePath);
    }
}
