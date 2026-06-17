<?php

namespace App\Tests\Integration;

use App\Service\Media\ImageMetadataSanitizer;
use App\Tests\Support\TestImageFactory;
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

    public function testReportsSupportedMimeTypes(): void
    {
        $sanitizer = $this->sanitizer();

        self::assertTrue($sanitizer->supportsMimeType('image/jpeg'));
        self::assertTrue($sanitizer->supportsMimeType('image/png'));
        self::assertTrue($sanitizer->supportsMimeType('image/webp'));
        self::assertFalse($sanitizer->supportsMimeType('image/svg+xml'));
        self::assertFalse($sanitizer->supportsMimeType(null));
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

    public function testJpegXmpMarkerIsDetectedAndRemovedBySanitizing(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is required for image sanitizer integration tests.');
        }

        $publicPath = $this->createJpegPublicUpload(24, 12);
        file_put_contents($this->absolutePath($publicPath), '<x:xmpmeta>camera metadata</x:xmpmeta>', FILE_APPEND);
        $sanitizer = $this->sanitizer();

        $inspection = $sanitizer->inspectPublicPath($publicPath);

        self::assertSame('image/jpeg', $inspection['mimeType']);
        self::assertContains('XMP', $inspection['markers']);
        self::assertTrue($inspection['hasSensitiveMetadata']);

        $result = $sanitizer->sanitizePublicPath($publicPath, applyOrientation: false);

        self::assertContains('XMP', $result['markersBefore']);
        self::assertNotContains('XMP', $result['markersAfter']);
        self::assertSame(24, $result['width']);
        self::assertSame(12, $result['height']);
    }

    public function testPngTextMarkerIsDetectedAndRemovedBySanitizing(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is required for image sanitizer integration tests.');
        }

        $publicPath = $this->createPngPublicUpload(22, 11);
        file_put_contents($this->absolutePath($publicPath), 'tEXtAuthor'.random_bytes(4).'iTXtComment', FILE_APPEND);
        $sanitizer = $this->sanitizer();

        $inspection = $sanitizer->inspectPublicPath($publicPath);

        self::assertSame('image/png', $inspection['mimeType']);
        self::assertContains('PNG_TEXT', $inspection['markers']);
        self::assertTrue($inspection['hasSensitiveMetadata']);

        $result = $sanitizer->sanitizePublicPath($publicPath);

        self::assertContains('PNG_TEXT', $result['markersBefore']);
        self::assertNotContains('PNG_TEXT', $result['markersAfter']);
        self::assertSame(22, $result['width']);
        self::assertSame(11, $result['height']);
    }

    public function testWebpXmpMarkerIsDetectedAndRemovedBySanitizing(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('GD WebP read support is required for this sanitizer integration test.');
        }

        $file = TestImageFactory::createWebp($this->uploadDirectory(), 20, 10, 'sanitizer-'.$this->uniqueToken('webp').'.webp');
        $this->createdFiles[] = $file;
        $publicPath = $this->publicPath($file);
        file_put_contents($file, 'XMP <x:xmpmeta>webp metadata</x:xmpmeta>', FILE_APPEND);
        $sanitizer = $this->sanitizer();

        $inspection = $sanitizer->inspectPublicPath($publicPath);

        self::assertSame('image/webp', $inspection['mimeType']);
        self::assertContains('XMP', $inspection['markers']);
        self::assertTrue($inspection['supported']);

        $result = $sanitizer->sanitizePublicPath($publicPath);

        self::assertContains('XMP', $result['markersBefore']);
        self::assertNotContains('XMP', $result['markersAfter']);
        self::assertSame(20, $result['width']);
        self::assertSame(10, $result['height']);
    }

    public function testGifCanBeInspectedButNotSanitizedWithoutSupport(): void
    {
        $file = $this->createUploadFile('unsupported.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==') ?: '');
        $sanitizer = $this->sanitizer();
        $inspection = $sanitizer->inspectPublicPath($this->publicPath($file));

        self::assertSame('image/gif', $inspection['mimeType']);
        self::assertFalse($inspection['supported']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ne peut pas être nettoyé');

        $sanitizer->sanitizePublicPath($this->publicPath($file));
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
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
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

    private function createJpegPublicUpload(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $file = $this->uploadDirectory().'/sanitizer-'.$this->uniqueToken('jpg').'.jpg';
        imagejpeg($image, $file);
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

    private function absolutePath(string $publicPath): string
    {
        return dirname(__DIR__, 2).'/public/'.ltrim($publicPath, '/');
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
