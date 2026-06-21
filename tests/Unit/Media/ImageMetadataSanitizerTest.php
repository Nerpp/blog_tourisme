<?php

namespace App\Tests\Unit\Media;

use App\Service\Media\ImageMetadataSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ImageMetadataSanitizerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            self::markTestSkipped('GD avec support PNG est requis.');
        }

        $this->workspace = sys_get_temp_dir().'/blog-tourisme-metadata-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/public/uploads', 0775, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->workspace) || !is_dir($this->workspace)) {
            return;
        }

        $files = glob($this->workspace.'/public/uploads/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->workspace.'/public/uploads');
        rmdir($this->workspace.'/public');
        rmdir($this->workspace);
    }

    public function testImageWithoutMetadataKeepsItsUsefulProperties(): void
    {
        $this->createPng('plain.png', 80, 60);
        $service = $this->service();

        $before = $service->inspectPublicPath('/uploads/plain.png');
        $result = $service->sanitizePublicPath('/uploads/plain.png');

        self::assertFalse($before['hasSensitiveMetadata']);
        self::assertSame([], $before['markers']);
        self::assertSame(80, $result['width']);
        self::assertSame(60, $result['height']);
        self::assertSame([], $result['markersBefore']);
        self::assertSame([], $result['markersAfter']);
    }

    public function testPngTextMetadataIsRemovedWithoutChangingDimensions(): void
    {
        $path = $this->createPng('with-text.png', 96, 72);
        $this->insertPngChunk($path, 'tEXt', "Comment\0coordonnees privees");
        $service = $this->service();

        $before = $service->inspectPublicPath('/uploads/with-text.png');
        $result = $service->sanitizePublicPath('/uploads/with-text.png');

        self::assertSame(['PNG_TEXT'], $before['markers']);
        self::assertTrue($before['hasSensitiveMetadata']);
        self::assertSame(['PNG_TEXT'], $result['markersBefore']);
        self::assertSame([], $result['markersAfter']);
        self::assertSame(96, $result['width']);
        self::assertSame(72, $result['height']);
    }

    public function testJpegExifMetadataIsRemovedWithoutChangingDimensionsOrReadability(): void
    {
        if (!function_exists('imagejpeg') || !function_exists('imagecreatefromjpeg')) {
            self::markTestSkipped('GD avec support JPEG est requis.');
        }

        $path = $this->createJpeg('with-exif.jpg', 120, 80);
        $this->insertJpegExifSoftware($path, 'Blog Tourisme Test');
        $service = $this->service();

        $before = $service->inspectPublicPath('/uploads/with-exif.jpg');
        self::assertContains('EXIF', $before['markers']);
        self::assertStringContainsString("Exif\0\0", (string) file_get_contents($path));
        self::assertStringContainsString('Blog Tourisme Test', (string) file_get_contents($path));

        $result = $service->sanitizePublicPath('/uploads/with-exif.jpg');
        $imageSize = getimagesize($path);
        $image = imagecreatefromjpeg($path);

        self::assertContains('EXIF', $result['markersBefore']);
        self::assertSame([], $result['markersAfter']);
        self::assertSame(120, $result['width']);
        self::assertSame(80, $result['height']);
        self::assertIsArray($imageSize);
        self::assertSame([120, 80], array_slice($imageSize, 0, 2));
        self::assertNotFalse($image);
        self::assertStringNotContainsString("Exif\0\0", (string) file_get_contents($path));
        self::assertStringNotContainsString('Blog Tourisme Test', (string) file_get_contents($path));

        imagedestroy($image);
    }

    private function service(): ImageMetadataSanitizer
    {
        $parameters = $this->createStub(ParameterBagInterface::class);
        $parameters
            ->method('get')
            ->willReturn($this->workspace);

        return new ImageMetadataSanitizer($parameters);
    }

    private function createPng(string $filename, int $width, int $height): string
    {
        $path = $this->workspace.'/public/uploads/'.$filename;
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 120, 180));
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);

        return $path;
    }

    private function createJpeg(string $filename, int $width, int $height): string
    {
        $path = $this->workspace.'/public/uploads/'.$filename;
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 180, 90, 30));
        self::assertTrue(imagejpeg($image, $path, 90));
        imagedestroy($image);

        return $path;
    }

    private function insertJpegExifSoftware(string $path, string $software): void
    {
        $contents = (string) file_get_contents($path);
        self::assertStringStartsWith("\xFF\xD8", $contents);

        $value = $software."\0";
        $tiff = "II\x2A\x00\x08\x00\x00\x00"
            ."\x01\x00"
            ."\x31\x01\x02\x00"
            .pack('V', strlen($value))
            ."\x1A\x00\x00\x00"
            ."\x00\x00\x00\x00"
            .$value;
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        file_put_contents($path, substr($contents, 0, 2).$segment.substr($contents, 2));
    }

    private function insertPngChunk(string $path, string $type, string $data): void
    {
        $contents = (string) file_get_contents($path);
        $iendPosition = strrpos($contents, 'IEND');
        self::assertNotFalse($iendPosition);

        $chunkStart = $iendPosition - 4;
        $chunk = pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        file_put_contents($path, substr($contents, 0, $chunkStart).$chunk.substr($contents, $chunkStart));
    }
}
