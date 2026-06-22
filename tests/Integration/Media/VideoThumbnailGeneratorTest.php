<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\Media\VideoThumbnailGenerator;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;

final class VideoThumbnailGeneratorTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

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
        self::assertSame('/uploads/media/existing-thumb.jpg', $media->getThumbnailPath());
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

    public function testUnsupportedExternalVideoDoesNotAttemptLocalGeneration(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setExternalUrl('https://example.test/video')
            ->setFilePath('https://example.test/video.mp4');

        $thumbnail = $this->generator()->generateForMedia($media);

        self::assertNull($thumbnail);
        self::assertNull($media->getThumbnailPath());
    }

    public function testMissingLocalVideoReturnsNullWithoutChangingMedia(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Local)
            ->setFilePath('/uploads/media/missing-video.mp4')
            ->setThumbnailPath('');

        $thumbnail = $this->generator()->generateForMedia($media);

        self::assertNull($thumbnail);
        self::assertSame('', $media->getThumbnailPath());
    }

    public function testSymlinkResolvingOutsideMediaDirectoryIsRejected(): void
    {
        $outsideFile = sys_get_temp_dir().'/video-thumbnail-outside-'.bin2hex(random_bytes(4)).'.mp4';
        $symlink = TestImageFactory::publicMediaDirectory().'/video-thumbnail-link-'.bin2hex(random_bytes(4)).'.mp4';
        file_put_contents($outsideFile, 'outside media root');
        symlink($outsideFile, $symlink);
        $this->files[] = $symlink;
        $this->files[] = $outsideFile;

        self::assertNull($this->generator()->generateFromPublicPath(TestImageFactory::publicPathFor($symlink)));
    }

    private function generator(): VideoThumbnailGenerator
    {
        $generator = $this->service(VideoThumbnailGenerator::class);
        self::assertInstanceOf(VideoThumbnailGenerator::class, $generator);

        return $generator;
    }
}
