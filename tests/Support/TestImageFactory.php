<?php

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TestImageFactory
{
    public static function ensureGd(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            Assert::markTestSkipped('GD is required to generate test images.');
        }
    }

    public static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function testMediaDirectory(): string
    {
        $directory = self::projectDir().'/var/test-media';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    public static function publicMediaDirectory(): string
    {
        $directory = self::projectDir().'/public/uploads/media';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    public static function publicPathFor(string $absolutePath): string
    {
        return '/uploads/media/'.basename($absolutePath);
    }

    public static function createPng(string $directory, int $width = 80, int $height = 40, ?string $name = null): string
    {
        self::ensureGd();

        $file = rtrim($directory, '/').'/'.($name ?? 'test-'.bin2hex(random_bytes(6)).'.png');
        $image = imagecreatetruecolor($width, $height);
        Assert::assertNotFalse($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $background = imagecolorallocatealpha($image, 32, 120, 180, 20);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagepng($image, $file);
        imagedestroy($image);

        return $file;
    }

    public static function createJpeg(string $directory, int $width = 80, int $height = 40, ?string $name = null): string
    {
        self::ensureGd();

        $file = rtrim($directory, '/').'/'.($name ?? 'test-'.bin2hex(random_bytes(6)).'.jpg');
        $image = imagecreatetruecolor($width, $height);
        Assert::assertNotFalse($image);
        $background = imagecolorallocate($image, 220, 180, 90);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagejpeg($image, $file, 90);
        imagedestroy($image);

        return $file;
    }

    public static function createWebp(string $directory, int $width = 80, int $height = 40, ?string $name = null): string
    {
        if (!function_exists('imagewebp')) {
            Assert::markTestSkipped('GD WebP support is required to generate WebP fixtures.');
        }

        $file = rtrim($directory, '/').'/'.($name ?? 'test-'.bin2hex(random_bytes(6)).'.webp');
        $image = imagecreatetruecolor($width, $height);
        Assert::assertNotFalse($image);
        $background = imagecolorallocate($image, 90, 180, 120);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagewebp($image, $file, 90);
        imagedestroy($image);

        return $file;
    }

    public static function createTextFile(string $directory, string $extension = 'txt', string $contents = 'not an image'): string
    {
        $file = rtrim($directory, '/').'/test-'.bin2hex(random_bytes(6)).'.'.$extension;
        file_put_contents($file, $contents);

        return $file;
    }

    public static function createUploadedFile(string $path, ?string $clientName = null, ?string $mimeType = null): UploadedFile
    {
        return new UploadedFile(
            $path,
            $clientName ?? basename($path),
            $mimeType,
            null,
            true,
        );
    }
}
