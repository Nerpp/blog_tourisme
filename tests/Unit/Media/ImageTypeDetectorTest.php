<?php

namespace App\Tests\Unit\Media;

use App\Enum\ImageType;
use App\Service\Media\ImageTypeDetector;
use App\Tests\Support\TestImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageTypeDetectorTest extends TestCase
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

    /**
     * @param array{width: int, height: int, mimeType?: string|null, originalFilename?: string|null} $input
     */
    #[DataProvider('dimensionCases')]
    public function testDetectClassifiesDimensionsConservatively(array $input, ImageType $expected): void
    {
        self::assertSame(
            $expected,
            $this->detector()->detect(
                $input['width'],
                $input['height'],
                $input['mimeType'] ?? null,
                $input['originalFilename'] ?? null,
            ),
        );
    }

    /** @return iterable<string, array{input: array{width: int, height: int, mimeType?: string|null, originalFilename?: string|null}, expected: ImageType}> */
    public static function dimensionCases(): iterable
    {
        yield 'invalid width falls back to standard' => [
            'input' => ['width' => 0, 'height' => 100, 'mimeType' => 'image/jpeg'],
            'expected' => ImageType::Standard,
        ];

        yield 'invalid height falls back to standard' => [
            'input' => ['width' => 100, 'height' => 0, 'mimeType' => 'image/jpeg'],
            'expected' => ImageType::Standard,
        ];

        yield 'filename containing 180 wins before ratio checks' => [
            'input' => ['width' => 800, 'height' => 600, 'mimeType' => 'image/jpeg', 'originalFilename' => 'balade-180-preview.jpg'],
            'expected' => ImageType::Degree180,
        ];

        yield 'large compatible two-to-one image is 360' => [
            'input' => ['width' => 3000, 'height' => 1500, 'mimeType' => 'image/jpeg'],
            'expected' => ImageType::Degree360,
        ];

        yield 'compatible MIME is trimmed and case-insensitive for 360' => [
            'input' => ['width' => 4000, 'height' => 2000, 'mimeType' => ' IMAGE/WEBP '],
            'expected' => ImageType::Degree360,
        ];

        yield 'smaller two-to-one image is 180' => [
            'input' => ['width' => 2000, 'height' => 1000, 'mimeType' => 'image/png'],
            'expected' => ImageType::Degree180,
        ];

        yield 'unsupported two-to-one MIME is not 360' => [
            'input' => ['width' => 4000, 'height' => 2000, 'mimeType' => 'image/gif'],
            'expected' => ImageType::WideAngle,
        ];

        yield 'very wide image is panorama' => [
            'input' => ['width' => 2300, 'height' => 1000, 'mimeType' => 'image/jpeg'],
            'expected' => ImageType::Panorama,
        ];

        yield 'wide image is wide angle' => [
            'input' => ['width' => 1700, 'height' => 1000, 'mimeType' => 'image/jpeg'],
            'expected' => ImageType::WideAngle,
        ];

        yield 'square image is standard' => [
            'input' => ['width' => 1200, 'height' => 1200, 'mimeType' => 'image/jpeg'],
            'expected' => ImageType::Standard,
        ];
    }

    public function testDetectFromUploadClassifiesValidJpeg(): void
    {
        $file = $this->uploadedImage(TestImageFactory::createJpeg($this->testDirectory(), 3200, 1600), 'pano.jpg', 'image/jpeg');

        self::assertSame(ImageType::Degree360, $this->detector()->detectFromUpload($file));
    }

    public function testDetectFromUploadClassifiesValidPng(): void
    {
        $file = $this->uploadedImage(TestImageFactory::createPng($this->testDirectory(), 1800, 1000), 'wide.png', 'image/png');

        self::assertSame(ImageType::WideAngle, $this->detector()->detectFromUpload($file));
    }

    public function testDetectFromUploadClassifiesValidWebp(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('GD WebP support is required for this detector unit test.');
        }

        $file = $this->uploadedImage(TestImageFactory::createWebp($this->testDirectory(), 2400, 1000), 'panorama.webp', 'image/webp');

        self::assertSame(ImageType::Panorama, $this->detector()->detectFromUpload($file));
    }

    public function testDetectFromUploadUsesOriginalFilenameFor180Hint(): void
    {
        $file = $this->uploadedImage(TestImageFactory::createJpeg($this->testDirectory(), 800, 600), 'sortie-180.jpg', 'image/jpeg');

        self::assertSame(ImageType::Degree180, $this->detector()->detectFromUpload($file));
    }

    public function testDetectFromUploadDoesNotTreatGifAs360Compatible(): void
    {
        if (!function_exists('imagegif')) {
            self::markTestSkipped('GD GIF support is required for this detector unit test.');
        }

        $file = $this->uploadedImage($this->createGif(4000, 2000), 'drone.gif', 'image/gif');

        self::assertSame(ImageType::WideAngle, $this->detector()->detectFromUpload($file));
    }

    public function testDetectFromUploadFallsBackToStandardForUnreadableFiles(): void
    {
        $invalid = $this->createTextFile('photo.jpg', 'not an image');
        $empty = $this->createTextFile('empty', '');
        $svg = $this->createTextFile('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        self::assertSame(ImageType::Standard, $this->detector()->detectFromUpload($this->uploadedImage($invalid, 'photo.jpg', 'image/jpeg')));
        self::assertSame(ImageType::Standard, $this->detector()->detectFromUpload($this->uploadedImage($empty, 'empty', null)));
        self::assertSame(ImageType::Standard, $this->detector()->detectFromUpload($this->uploadedImage($svg, 'vector.svg', 'image/svg+xml')));
    }

    public function testDetectFromUploadUsesRealImageMimeBeforeClientMime(): void
    {
        $file = $this->uploadedImage(TestImageFactory::createJpeg($this->testDirectory(), 3200, 1600), 'photo.png', 'image/png');

        self::assertSame(ImageType::Degree360, $this->detector()->detectFromUpload($file));
    }

    private function detector(): ImageTypeDetector
    {
        return new ImageTypeDetector();
    }

    private function uploadedImage(string $path, string $clientName, ?string $mimeType): UploadedFile
    {
        $this->files[] = $path;

        return TestImageFactory::createUploadedFile($path, $clientName, $mimeType);
    }

    private function createTextFile(string $name, string $contents): string
    {
        $path = $this->testDirectory().'/'.$name;
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private function createGif(int $width, int $height): string
    {
        TestImageFactory::ensureGd();

        $path = $this->testDirectory().'/test-'.bin2hex(random_bytes(6)).'.gif';
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $color = imagecolorallocate($image, 80, 120, 160);
        self::assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagegif($image, $path);
        imagedestroy($image);
        $this->files[] = $path;

        return $path;
    }

    private function testDirectory(): string
    {
        return TestImageFactory::testMediaDirectory();
    }
}
