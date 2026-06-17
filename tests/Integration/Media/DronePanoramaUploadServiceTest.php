<?php

namespace App\Tests\Integration\Media;

use App\Service\Media\DronePanoramaUploadService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;
use InvalidArgumentException;

final class DronePanoramaUploadServiceTest extends IntegrationTestCase
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

    public function testValidEquirectangularJpegIsStoredWithViewerThumbnailAndMetadata(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 400, 200, 'panorama-test.jpg');
        $this->files[] = $source;
        $upload = TestImageFactory::createUploadedFile($source, 'DJI Panorama Test.JPG', 'image/jpeg');

        $result = $this->panoramaUploadService()->upload($upload, 'Drone Test');

        self::assertSame('DJI Panorama Test', $result['title']);
        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertSame('equirectangular', $result['projection']);
        self::assertSame(400, $result['width']);
        self::assertSame(200, $result['height']);
        self::assertTrue($result['metadata']['metadataSanitized']);
        self::assertSame('/uploads/media/360', substr($result['path'], 0, 18));
        $this->assertPublicImage($result['path'], 'image/jpeg', 400, 200);
        $this->assertPublicImage($result['thumbnailPath'], 'image/jpeg', 400, 200);
        $this->assertPublicImage($result['metadata']['originalPath'], 'image/jpeg', 400, 200);
        self::assertNull($result['metadata']['mobilePath']);
    }

    public function testValidEquirectangularPngKeepsTransparencyCapablePipeline(): void
    {
        $source = TestImageFactory::createPng(TestImageFactory::testMediaDirectory(), 400, 200, 'panorama-test.png');
        $this->files[] = $source;
        $upload = TestImageFactory::createUploadedFile($source, 'Transparent Panorama.PNG', 'image/png');

        $result = $this->panoramaUploadService()->upload($upload, 'Transparent Drone');

        self::assertSame('Transparent Panorama', $result['title']);
        self::assertSame('image/png', $result['mimeType']);
        self::assertSame(400, $result['width']);
        self::assertSame(200, $result['height']);
        self::assertSame('equirectangular', $result['projection']);
        $this->assertPublicImage($result['path'], 'image/png', 400, 200);
        $this->assertPublicImage($result['thumbnailPath'], 'image/png', 400, 200);
        $this->assertPublicImage($result['metadata']['originalPath'], 'image/png', 400, 200);
    }

    public function testRejectsNonEquirectangularImage(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 200, 200, 'square.jpg');
        $this->files[] = $source;

        $this->expectException(InvalidArgumentException::class);
        $this->panoramaUploadService()->upload(TestImageFactory::createUploadedFile($source, 'square.jpg', 'image/jpeg'));
    }

    public function testRejectsExtensionThatDoesNotMatchRealMimeType(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 400, 200, 'mismatch.jpg');
        $this->files[] = $source;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('l’extension du fichier ne correspond pas au type réel de l’image.');

        $this->panoramaUploadService()->upload(TestImageFactory::createUploadedFile($source, 'panorama.png', 'image/jpeg'));
    }

    public function testRejectsSvgAndPhpRenamedAsImage(): void
    {
        $svg = TestImageFactory::createTextFile(TestImageFactory::testMediaDirectory(), 'svg', '<svg><script>alert(1)</script></svg>');
        $phpJpg = TestImageFactory::createTextFile(TestImageFactory::testMediaDirectory(), 'jpg', '<?php echo "x";');
        $this->files[] = $svg;
        $this->files[] = $phpJpg;

        foreach ([
            TestImageFactory::createUploadedFile($svg, 'panorama.svg', 'image/svg+xml'),
            TestImageFactory::createUploadedFile($phpJpg, 'panorama.jpg', 'image/jpeg'),
        ] as $upload) {
            try {
                $this->panoramaUploadService()->upload($upload);
                self::fail('Invalid panorama upload should have been rejected.');
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
    }

    private function panoramaUploadService(): DronePanoramaUploadService
    {
        $service = $this->service(DronePanoramaUploadService::class);
        self::assertInstanceOf(DronePanoramaUploadService::class, $service);

        return $service;
    }
}
