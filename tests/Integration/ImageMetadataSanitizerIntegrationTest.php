<?php

namespace App\Tests\Integration;

use App\Service\Media\ImageMetadataSanitizer;
use InvalidArgumentException;

final class ImageMetadataSanitizerIntegrationTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testValidPngCanBeInspectedAndSanitizedWithDimensionsPreserved(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is required for image sanitizer integration tests.');
        }

        $publicPath = $this->createPngPublicUpload(32, 18);
        $sanitizer = $this->sanitizer();

        $inspection = $sanitizer->inspectPublicPath($publicPath);
        self::assertSame('image/png', $inspection['mimeType']);
        self::assertSame(32, $inspection['width']);
        self::assertSame(18, $inspection['height']);
        self::assertTrue($inspection['supported']);

        $result = $sanitizer->sanitizePublicPath($publicPath);
        self::assertSame(32, $result['width']);
        self::assertSame(18, $result['height']);
        self::assertSame(32, $result['previousWidth']);
        self::assertSame(18, $result['previousHeight']);
    }

    public function testNonImageUploadIsRejected(): void
    {
        $file = $this->createUploadFile('not-image.txt', 'not an image');
        $this->expectException(InvalidArgumentException::class);

        $this->sanitizer()->inspectPublicPath($this->publicPath($file));
    }

    public function testMissingFileAndExternalUrlAreRejected(): void
    {
        $sanitizer = $this->sanitizer();

        try {
            $sanitizer->inspectPublicPath('/uploads/media/missing-sanitizer-file.png');
            self::fail('Missing file should have been rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $sanitizer->inspectPublicPath('https://example.test/photo.jpg');
    }

    public function testPublicPathForAbsolutePathRejectsFilesOutsideUploads(): void
    {
        $file = $this->createUploadFile('path-check.txt', 'content');
        $sanitizer = $this->sanitizer();

        self::assertSame($this->publicPath($file), $sanitizer->publicPathForAbsolutePath($file));

        $this->expectException(InvalidArgumentException::class);
        $sanitizer->publicPathForAbsolutePath('/tmp/outside-image-sanitizer-test.txt');
    }

    private function createPngPublicUpload(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $file = $this->uploadDirectory().'/sanitizer-'.$this->uniqueToken('png').'.png';
        imagepng($image, $file);
        imagedestroy($image);
        $this->createdFiles[] = $file;

        return $this->publicPath($file);
    }

    private function createUploadFile(string $name, string $contents): string
    {
        $file = $this->uploadDirectory().'/sanitizer-'.$this->uniqueToken($name);
        file_put_contents($file, $contents);
        $this->createdFiles[] = $file;

        return $file;
    }

    private function publicPath(string $absolutePath): string
    {
        return '/uploads/media/'.basename($absolutePath);
    }

    private function uploadDirectory(): string
    {
        $directory = dirname(__DIR__, 2).'/public/uploads/media';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function sanitizer(): ImageMetadataSanitizer
    {
        $sanitizer = $this->service(ImageMetadataSanitizer::class);
        self::assertInstanceOf(ImageMetadataSanitizer::class, $sanitizer);

        return $sanitizer;
    }
}
