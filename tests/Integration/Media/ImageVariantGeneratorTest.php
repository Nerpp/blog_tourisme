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

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testGeneratesVariantsForValidJpegWithoutUpscalingSmallImage(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 80, 40);
        $this->files[] = $source;

        $variants = $this->generator()->generate(TestImageFactory::publicPathFor($source), 'jpeg-test');

        foreach (['thumb', 'medium', 'large'] as $size) {
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

        self::assertSame(600, $first['thumb']['width']);
        self::assertSame(300, $first['thumb']['height']);
        self::assertSame(800, $first['medium']['width']);
        self::assertSame(400, $first['medium']['height']);
        self::assertSame($first['thumb']['fallback'], $second['thumb']['fallback']);
        self::assertSame($first['medium']['fallback'], $second['medium']['fallback']);
        self::assertSame($first['large']['fallback'], $second['large']['fallback']);
        $this->assertPublicImage($second['thumb']['fallback'], 'image/jpeg', 600, 300);
        $this->assertPublicImage($second['medium']['fallback'], 'image/jpeg', 800, 400);
        $this->assertPublicImage($second['large']['fallback'], 'image/jpeg', 800, 400);
    }

    public function testReportsSupportedFormatsAndMimeTypes(): void
    {
        $generator = $this->generator();
        $formats = $generator->supportedOutputFormats();

        self::assertSame('fallback', $formats[0]);
        self::assertSame(in_array('webp', $formats, true), $generator->supportsWebp());
        self::assertSame(in_array('avif', $formats, true), $generator->supportsAvif());
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

    public function testRejectsExternalSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('médias externes');

        $this->generator()->generate('https://example.test/photo.jpg');
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

    private function generator(): ImageVariantGenerator
    {
        $generator = $this->service(ImageVariantGenerator::class);
        self::assertInstanceOf(ImageVariantGenerator::class, $generator);

        return $generator;
    }
}
