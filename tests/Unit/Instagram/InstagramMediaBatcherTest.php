<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\MediaAsset;
use App\Service\Instagram\InstagramMedia;
use App\Service\Instagram\InstagramMediaBatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstagramMediaBatcherTest extends TestCase
{
    /** @param list<int> $expectedBatchSizes */
    #[DataProvider('mediaCountProvider')]
    public function testItBuildsBatchesOfAtMostTenMedia(int $mediaCount, array $expectedBatchSizes): void
    {
        $media = [];
        for ($index = 1; $index <= $mediaCount; ++$index) {
            $asset = new MediaAsset();
            $this->setId($asset, $index);
            $media[] = new InstagramMedia($asset, sprintf('https://estela-exploration.fr/uploads/media/%d.webp', $index));
        }

        $batches = (new InstagramMediaBatcher())->batch($media);

        self::assertSame($expectedBatchSizes, array_map('count', $batches));
        self::assertSame(
            array_map(static fn(InstagramMedia $item): ?int => $item->mediaId, $media),
            array_map(
                static fn(InstagramMedia $item): ?int => $item->mediaId,
                array_merge(...$batches),
            ),
        );
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function mediaCountProvider(): iterable
    {
        yield 'zero' => [0, []];
        yield 'one' => [1, [1]];
        yield 'two' => [2, [2]];
        yield 'ten' => [10, [10]];
        yield 'eleven' => [11, [10, 1]];
        yield 'twenty three' => [23, [10, 10, 3]];
    }

    public function testMediaDtoKeepsTheAssetReferenceAndRejectsNonHttpsUrls(): void
    {
        $asset = new MediaAsset();
        $this->setId($asset, 42);
        $media = new InstagramMedia($asset, ' https://cdn.example.test/photo.webp ');

        self::assertSame($asset, $media->mediaAsset);
        self::assertSame(42, $media->mediaId);
        self::assertSame('https://cdn.example.test/photo.webp', $media->url);

        $this->expectException(\InvalidArgumentException::class);
        new InstagramMedia($asset, 'http://cdn.example.test/photo.webp');
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
