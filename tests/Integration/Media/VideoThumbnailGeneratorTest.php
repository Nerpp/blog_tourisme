<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\Media\VideoThumbnailGenerator;
use App\Tests\Integration\IntegrationTestCase;

final class VideoThumbnailGeneratorTest extends IntegrationTestCase
{
    public function testYoutubeVideoUsesExternalThumbnailWithoutFfmpeg(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $thumbnail = $this->generator()->generateForMedia($media);

        self::assertSame('https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $thumbnail);
        self::assertSame($thumbnail, $media->getThumbnailPath());
    }

    public function testExistingThumbnailIsKeptUnlessOverwriteIsRequested(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Local)
            ->setFilePath('/uploads/media/missing-video.mp4')
            ->setThumbnailPath('/uploads/media/existing-thumb.jpg');

        self::assertSame('/uploads/media/existing-thumb.jpg', $this->generator()->generateForMedia($media));
        self::assertNull($this->generator()->generateForMedia($media, overwrite: true));
    }

    public function testUnsafeOrMissingPublicPathReturnsNull(): void
    {
        $generator = $this->generator();

        self::assertNull($generator->generateFromPublicPath('/uploads/media/../secret.mp4'));
        self::assertNull($generator->generateFromPublicPath('https://example.test/video.mp4'));
        self::assertNull($generator->generateFromPublicPath('/uploads/media/missing-video.mp4'));
    }

    public function testNonVideoMediaIsIgnored(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/photo.jpg');

        self::assertNull($this->generator()->generateForMedia($media));
    }

    private function generator(): VideoThumbnailGenerator
    {
        $generator = $this->service(VideoThumbnailGenerator::class);
        self::assertInstanceOf(VideoThumbnailGenerator::class, $generator);

        return $generator;
    }
}
