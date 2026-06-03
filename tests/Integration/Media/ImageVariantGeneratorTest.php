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
        }
    }

    public function testGeneratesVariantsForValidPng(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 64, 32);
        $this->files[] = $source;

        $variants = $this->generator()->generate(TestImageFactory::publicPathFor($source), 'png-test');

        self::assertSame('image/png', $variants['source']['mimeType']);
        $this->assertPublicImage($variants['thumb']['fallback'], 'image/png', 64, 32);
    }

    public function testRejectsMissingSourceAndNonImages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->generate('/uploads/media/missing-variant-source.jpg');
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
