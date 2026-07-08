<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\ContentStatus;
use App\Enum\MediaType;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Service\Media\MediaDeletionService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;

final class MediaDeletionServiceTest extends IntegrationTestCase
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

        parent::tearDown();
    }

    public function testDeleteIfOrphanDeletesLocalFilesVariantsMetadataAndEntity(): void
    {
        $main = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory());
        $thumb = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory());
        $variant = TestImageFactory::createPng(TestImageFactory::publicMediaDirectory(), 32, 16);
        $original = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 100, 50);
        $this->files = [$main, $thumb, $variant, $original];

        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($main))
            ->setThumbnailPath(TestImageFactory::publicPathFor($thumb))
            ->setVariants(['thumb' => ['fallback' => TestImageFactory::publicPathFor($variant)]])
            ->setMetadata(['originalPath' => TestImageFactory::publicPathFor($original)]);
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        $mediaId = $media->getId();

        $result = $this->deletionService()->deleteIfOrphan($media);
        $this->entityManager->flush();

        self::assertTrue($result['deleted']);
        self::assertFalse($result['skipped']);
        self::assertCount(4, $result['files']);
        foreach ([$main, $thumb, $variant, $original] as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertNull($this->entityManager->find(MediaAsset::class, $mediaId));
    }

    public function testDangerousExternalAndDemoPathsAreIgnoredForDeletion(): void
    {
        $safe = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory());
        $this->files[] = $safe;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($safe))
            ->setThumbnailPath('https://example.test/thumb.jpg')
            ->setVariants([
                'large' => [
                    'fallback' => '/uploads/media/../secret.jpg',
                    'webp' => '/uploads/demo/demo.webp',
                ],
            ])
            ->setMetadata(['originalPath' => 'php://filter']);

        $files = $this->deletionService()->localUploadFiles($media);

        self::assertSame([realpath($safe)], $files);
    }

    public function testExternalOnlyMediaIsSkipped(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setExternalUrl('https://example.test/video');

        $result = $this->deletionService()->deleteIfOrphan($media);

        self::assertTrue($result['skipped']);
        self::assertSame('média externe sans fichier local', $result['reason']);
    }

    public function testMediaStillUsedByPlaceIsNotDeleted(): void
    {
        $main = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory());
        $this->files[] = $main;
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($main));
        $place = (new Place())
            ->setName('Lieu media deletion '.$this->uniqueToken('place'))
            ->setSlug($this->uniqueToken('place-slug'))
            ->setStatus(ContentStatus::Published)
            ->setDifficulty(PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::Unknown)
            ->setFeaturedImage($media);

        $this->entityManager->persist($media);
        $this->entityManager->persist($place);
        $this->entityManager->flush();

        $result = $this->deletionService()->deleteIfOrphan($media);

        self::assertTrue($result['skipped']);
        self::assertSame(1, $this->deletionService()->usage($media)['usageCount']);
        self::assertFileExists($main);
    }

    private function deletionService(): MediaDeletionService
    {
        $service = $this->service(MediaDeletionService::class);
        self::assertInstanceOf(MediaDeletionService::class, $service);

        return $service;
    }
}
