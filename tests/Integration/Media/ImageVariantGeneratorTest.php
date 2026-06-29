<?php

namespace App\Tests\Integration\Media;

use App\Service\Media\ImageVariantGenerator;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;
use InvalidArgumentException;

final class ImageVariantGeneratorTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }

        foreach (array_reverse($this->directories) as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function testGeneratesCoreAndSecondaryWebpVariantsForStandardImage(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 2000, 1000);
        $this->files[] = $source;

        $variants = $this->generator()->generateStandard(TestImageFactory::publicPathFor($source), 'standard-webp-only');

        self::assertSame(['webp'], $variants['source']['formats']);
        foreach ([
            'thumb' => [600, 300],
            'mobile' => [960, 480],
            'medium' => [1600, 800],
            'large' => [1920, 960],
            'thumbnail320' => [320, 160],
            'thumbnail480' => [480, 240],
            'content640' => [640, 320],
            'content768' => [768, 384],
            'content960' => [960, 480],
        ] as $size => [$width, $height]) {
            self::assertSame(['webp', 'width', 'height'], array_keys($variants[$size]));
            self::assertArrayNotHasKey('fallback', $variants[$size]);
            self::assertArrayNotHasKey('fallbackFormat', $variants[$size]);
            self::assertArrayNotHasKey('avif', $variants[$size]);
            $this->assertPublicImage($variants[$size]['webp'], 'image/webp', $width, $height);
        }
    }

    public function testGeneratesOnlySecondaryVariantsFromARetainedWebp(): void
    {
        $source = TestImageFactory::createWebp(TestImageFactory::publicMediaDirectory(), 1920, 960);
        $this->files[] = $source;

        $variants = $this->generator()->generateStandardSecondary(
            TestImageFactory::publicPathFor($source),
            'retained-standard-webp',
        );

        self::assertSame(
            ['thumbnail320', 'thumbnail480', 'content640', 'content768', 'content960'],
            array_keys($variants),
        );
        foreach ([
            'thumbnail320' => [320, 160],
            'thumbnail480' => [480, 240],
            'content640' => [640, 320],
            'content768' => [768, 384],
            'content960' => [960, 480],
        ] as $size => [$width, $height]) {
            $this->assertPublicImage($variants[$size]['webp'], 'image/webp', $width, $height);
        }
    }

    public function testGeneratesResponsiveWebpsForArticleImage(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 2400, 1200);
        $this->files[] = $source;

        $result = $this->generator()->generateArticleResponsiveWebps(
            TestImageFactory::publicPathFor($source),
            'article-responsive-webp',
        );

        self::assertSame(['webp'], $result['source']['formats']);
        self::assertSame('image/webp', $result['source']['mimeType']);
        foreach ([
            'thumb' => [640, 320, '_inline.webp'],
            'mobile' => [960, 480, '_display.webp'],
            'medium' => [1280, 640, '_cover.webp'],
            'large' => [1600, 800, '_source.webp'],
        ] as $size => [$width, $height, $suffix]) {
            self::assertStringStartsWith('/uploads/media/article_', $result[$size]['webp']);
            self::assertStringEndsWith($suffix, $result[$size]['webp']);
            self::assertGreaterThan(0, $result[$size]['fileSize']);
            $this->assertPublicImage($result[$size]['webp'], 'image/webp', $width, $height);
        }
        self::assertSame($result['large']['webp'], $result['source']['path']);
        self::assertSame(1600, $result['source']['width']);
        self::assertSame(800, $result['source']['height']);
    }

    public function testStandardVariantsDoNotUpscaleAndReuseOnePhysicalFilePerDimension(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 1242, 621);
        $this->files[] = $source;

        $variants = $this->generator()->generateStandard(TestImageFactory::publicPathFor($source), 'standard-deduplicated');

        self::assertSame(600, $variants['thumb']['width']);
        self::assertSame(960, $variants['mobile']['width']);
        self::assertSame(1242, $variants['medium']['width']);
        self::assertSame(1242, $variants['large']['width']);
        self::assertSame($variants['medium'], $variants['large']);

        $paths = array_map(
            static fn (string $size): string => $variants[$size]['webp'],
            ['thumb', 'mobile', 'medium', 'large'],
        );
        self::assertCount(3, array_unique($paths));
        foreach (array_unique($paths) as $path) {
            $dimensions = getimagesize(TestImageFactory::projectDir().'/public'.(string) $path);
            self::assertIsArray($dimensions);
            $this->files[] = TestImageFactory::projectDir().'/public'.(string) $path;
        }
    }

    public function testSmallStandardImageUsesSinglePhysicalWebpForAllSizes(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 320, 160);
        $this->files[] = $source;

        $variants = $this->generator()->generateStandard(TestImageFactory::publicPathFor($source), 'standard-small');
        $paths = array_map(
            static fn (string $size): string => $variants[$size]['webp'],
            ['thumb', 'mobile', 'medium', 'large'],
        );

        self::assertCount(1, array_unique($paths));
        self::assertSame(320, $variants['large']['width']);
        self::assertSame(160, $variants['large']['height']);
        $this->assertPublicImage($paths[0], 'image/webp', 320, 160);
    }

    public function testGeneratesVariantsForValidJpegWithoutUpscalingSmallImage(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 80, 40);
        $this->files[] = $source;

        $variants = $this->generator()->generate(TestImageFactory::publicPathFor($source), 'jpeg-test');

        foreach (['thumb', 'mobile', 'medium', 'large'] as $size) {
            self::assertArrayHasKey($size, $variants);
            self::assertSame(80, $variants[$size]['width']);
            self::assertSame(40, $variants[$size]['height']);
            self::assertSame('image/jpeg', $variants[$size]['fallbackFormat']);
            $this->assertPublicImage($variants[$size]['fallback'], 'image/jpeg', 80, 40);

            if (isset($variants[$size]['webp'])) {
                $this->assertPublicImage($variants[$size]['webp'], 'image/webp', 80, 40);
            }

            if (isset($variants[$size]['avif'])) {
                $this->assertPublicImage($variants[$size]['avif'], 'image/avif', 80, 40);
            }
        }
    }

    public function testDownsizesLargeImageAndRegeneratesStableVariantPaths(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 800, 400);
        $this->files[] = $source;
        $generator = $this->generator();

        $first = $generator->generate(TestImageFactory::publicPathFor($source), 'stable-large-image');
        $second = $generator->generate(TestImageFactory::publicPathFor($source), 'stable-large-image');

        self::assertSame(640, $first['thumb']['width']);
        self::assertSame(320, $first['thumb']['height']);
        self::assertSame(800, $first['medium']['width']);
        self::assertSame(400, $first['medium']['height']);
        self::assertSame($first['thumb']['fallback'], $second['thumb']['fallback']);
        self::assertSame($first['medium']['fallback'], $second['medium']['fallback']);
        self::assertSame($first['large']['fallback'], $second['large']['fallback']);
        $this->assertPublicImage($second['thumb']['fallback'], 'image/jpeg', 640, 320);
        $this->assertPublicImage($second['mobile']['fallback'], 'image/jpeg', 800, 400);
        $this->assertPublicImage($second['medium']['fallback'], 'image/jpeg', 800, 400);
        $this->assertPublicImage($second['large']['fallback'], 'image/jpeg', 800, 400);
    }

    public function testLegacyPipelineGeneratesCoverCandidatesAtTargetDimensionsAndQualities(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 1600, 800);
        $this->files[] = $source;

        $variants = $this->generator()->generate(TestImageFactory::publicPathFor($source), 'cover-targets');

        foreach ([
            'thumb' => [640, 320],
            'mobile' => [960, 480],
            'medium' => [1280, 640],
            'large' => [1600, 800],
        ] as $size => [$width, $height]) {
            $this->assertPublicImage($variants[$size]['fallback'], 'image/jpeg', $width, $height);
            if (isset($variants[$size]['webp'])) {
                $this->assertPublicImage($variants[$size]['webp'], 'image/webp', $width, $height);
            }
        }

        self::assertSame(
            ['thumb' => 76, 'mobile' => 78, 'medium' => 80, 'large' => 90],
            (new \ReflectionClass(ImageVariantGenerator::class))->getConstant('LEGACY_WEBP_QUALITIES'),
        );
    }

    public function testReportsSupportedFormatsAndMimeTypes(): void
    {
        $generator = $this->generator();
        $formats = $generator->supportedOutputFormats();

        self::assertSame('fallback', $formats[0]);
        self::assertSame(in_array('webp', $formats, true), $generator->supportsWebp());
        self::assertSame(in_array('avif', $formats, true), $generator->supportsAvif());
        self::assertSame(['webp'], $generator->standardOutputFormats());
        self::assertTrue($generator->supportsMimeType('image/jpeg'));
        self::assertTrue($generator->supportsMimeType('image/png'));
        self::assertTrue($generator->supportsMimeType('image/webp'));
        self::assertFalse($generator->supportsMimeType('image/svg+xml'));
        self::assertFalse($generator->supportsMimeType(null));
    }

    public function testGeneratesVariantsForValidPng(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 64, 32);
        $this->files[] = $source;

        $variants = $this->generator()->generate(TestImageFactory::publicPathFor($source), 'png-test');

        self::assertSame('image/png', $variants['source']['mimeType']);
        $this->assertPublicImage($variants['thumb']['fallback'], 'image/png', 64, 32);
    }

    public function testGeneratesVariantsFromNestedMediaDirectory(): void
    {
        $directory = TestImageFactory::publicMediaDirectory().'/nested-source';
        mkdir($directory, 0775, true);
        $this->directories[] = $directory;
        $source = TestImageFactory::createJpeg($directory, 72, 36);
        $this->files[] = $source;

        $variants = $this->generator()->generate('/uploads/media/nested-source/'.basename($source), 'nested-source');

        self::assertSame('image/jpeg', $variants['source']['mimeType']);
        $this->assertPublicImage($variants['thumb']['fallback'], 'image/jpeg', 72, 36);
    }

    public function testGeneratesVariantsForValidWebp(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('GD WebP read support is required for this variant integration test.');
        }

        $source = TestImageFactory::createWebp(TestImageFactory::publicMediaDirectory(), 48, 24);
        $this->files[] = $source;

        $variants = $this->generator()->generate(TestImageFactory::publicPathFor($source), 'webp-test');

        self::assertSame('image/webp', $variants['source']['mimeType']);
        self::assertSame(['fallback', ...array_values(array_filter(['webp', 'avif'], fn (string $format): bool => in_array($format, $variants['source']['formats'], true)))], $variants['source']['formats']);
        $this->assertPublicImage($variants['thumb']['fallback'], 'image/webp', 48, 24);

        if (isset($variants['thumb']['webp'])) {
            self::assertSame($variants['thumb']['fallback'], $variants['thumb']['webp']);
        }

        if (isset($variants['thumb']['avif'])) {
            $this->assertPublicImage($variants['thumb']['avif'], 'image/avif', 48, 24);
        }
    }

    public function testRejectsMissingSourceAndNonImages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->generate('/uploads/media/missing-variant-source.jpg');
    }

    public function testRejectsExternalSources(): void
    {
        foreach (['http://example.test/photo.jpg', 'https://example.test/photo.jpg'] as $url) {
            $this->assertSourceRejected($url, 'médias externes');
        }
    }

    public function testRejectsTraversalWindowsAndPathsOutsideMediaRoot(): void
    {
        $outsideSource = TestImageFactory::createJpeg(
            TestImageFactory::projectDir().'/public/uploads',
            64,
            32,
        );
        $this->files[] = $outsideSource;

        foreach ([
            '/uploads/media/../'.basename($outsideSource),
            '/uploads/media..\\secret.jpg',
            '/uploads/avatars/photo.jpg',
            '/etc/passwd',
            '/var/www/html/.env',
        ] as $path) {
            $this->assertSourceRejected($path, 'chemin source média');
        }
    }

    public function testRejectsNeighbouringDirectorySharingPublicPrefix(): void
    {
        $directory = TestImageFactory::projectDir().'/publicity';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
            $this->directories[] = $directory;
        }
        $source = TestImageFactory::createJpeg($directory, 64, 32);
        $this->files[] = $source;

        $this->assertSourceRejected('/../publicity/'.basename($source), 'chemin source média');
    }

    public function testRejectsSymlinkToImageOutsideMediaRoot(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symbolic links are not supported.');
        }

        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 64, 32);
        $link = TestImageFactory::publicMediaDirectory().'/outside-'.bin2hex(random_bytes(6)).'.jpg';
        $this->files[] = $link;
        $this->files[] = $source;
        if (!@symlink($source, $link)) {
            self::markTestSkipped('Symbolic links cannot be created in this environment.');
        }

        $this->assertSourceRejected('/uploads/media/'.basename($link), 'hors du dossier public');
    }

    public function testRejectsSvgAndPhpRenamedAsImage(): void
    {
        $svg = TestImageFactory::createTextFile(
            TestImageFactory::publicMediaDirectory(),
            'svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );
        $phpJpg = TestImageFactory::createTextFile(TestImageFactory::publicMediaDirectory(), 'jpg', '<?php echo "x";');
        $this->files[] = $svg;
        $this->files[] = $phpJpg;

        foreach ([$svg, $phpJpg] as $file) {
            try {
                $this->generator()->generate(TestImageFactory::publicPathFor($file));
                self::fail('Invalid image should have been rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testRejectsVariantDimensionsThatRoundToZeroWithoutWritingOutput(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 10_000, 1);
        $this->files[] = $source;
        $variantPattern = TestImageFactory::projectDir().'/public/uploads/media/variants/media_*';
        $filesBefore = glob($variantPattern) ?: [];

        try {
            $this->generator()->generate(TestImageFactory::publicPathFor($source), 'invalid-rounded-height');
            self::fail('A variant with a calculated zero height must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Les dimensions calculées de la variante sont invalides.', $exception->getMessage());
        }

        self::assertSame(
            $filesBefore,
            glob($variantPattern) ?: [],
        );
    }

    private function assertPublicImage(string $publicPath, string $expectedMime, int $width, int $height): void
    {
        $file = TestImageFactory::projectDir().'/public/'.ltrim($publicPath, '/');
        $this->files[] = $file;
        self::assertFileExists($file);
        $imageSize = getimagesize($file);
        self::assertIsArray($imageSize);
        self::assertSame($expectedMime, $imageSize['mime']);
        self::assertSame($width, $imageSize[0]);
        self::assertSame($height, $imageSize[1]);
        self::assertGreaterThan(0, filesize($file));
    }

    private function assertSourceRejected(string $publicPath, string $message): void
    {
        try {
            $this->generator()->generate($publicPath);
            self::fail(sprintf('Source path "%s" should have been rejected.', $publicPath));
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function generator(): ImageVariantGenerator
    {
        $generator = $this->service(ImageVariantGenerator::class);
        self::assertInstanceOf(ImageVariantGenerator::class, $generator);

        return $generator;
    }
}
