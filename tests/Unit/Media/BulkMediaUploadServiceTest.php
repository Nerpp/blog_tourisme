<?php

namespace App\Tests\Unit\Media;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\BulkMediaUploadService;
use App\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class BulkMediaUploadServiceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testClientPolicyExposesCurrentLimits(): void
    {
        self::assertSame([
            'maxFiles' => 50,
            'classicMaxBytes' => 31_457_280,
            'panoramaMaxBytes' => 52_428_800,
        ], $this->service()->clientPolicy());
    }

    public function testSuccessPayloadUsesMasterPathVariantDisplayPathAndDetectedMetadata(): void
    {
        $file = TestImageFactory::createTextFile(TestImageFactory::testMediaDirectory(), 'jpg');
        $this->files[] = $file;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath('/uploads/media/original.jpg')
            ->setThumbnailPath('/uploads/media/thumb.jpg')
            ->setWidth(120)
            ->setHeight(60)
            ->setFileSize(1234)
            ->setMimeType('image/jpeg')
            ->setMetadata(['originalPath' => '/uploads/media/master.jpg'])
            ->setVariants(['large' => ['webp' => '/uploads/media/large.webp']]);

        $payload = $this->service()->successPayload(
            $media,
            TestImageFactory::createUploadedFile($file, '../dangerous-name.jpg', 'image/jpeg'),
        );

        self::assertTrue($payload['success']);
        self::assertSame('dangerous-name.jpg', $payload['originalDisplayName']);
        self::assertSame('/uploads/media/master.jpg', $payload['masterPath']);
        self::assertSame('/uploads/media/large.webp', $payload['displayPath']);
        self::assertSame('/uploads/media/thumb.jpg', $payload['thumbnailPath']);
        self::assertSame('panorama', $payload['mediaType']);
        self::assertSame('image/jpeg', $payload['detectedMime']);
    }

    public function testErrorPayloadKeepsOriginalDisplayNameForUiFeedback(): void
    {
        $file = TestImageFactory::createTextFile(TestImageFactory::testMediaDirectory(), 'svg');
        $this->files[] = $file;

        $payload = $this->service()->errorPayload(
            TestImageFactory::createUploadedFile($file, 'bad.svg', 'image/svg+xml'),
            'Format refusé.',
        );

        self::assertFalse($payload['success']);
        self::assertSame('bad.svg', $payload['originalDisplayName']);
        self::assertSame('Format refusé.', $payload['error']);
    }

    private function service(): BulkMediaUploadService
    {
        return new BulkMediaUploadService();
    }
}
