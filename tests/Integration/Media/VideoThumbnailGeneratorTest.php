<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\Media\VideoThumbnailGenerator;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;
use Symfony\Component\Process\Process;

final class VideoThumbnailGeneratorTest extends IntegrationTestCase
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

    public function testLocalVideoGeneratesThumbnailAndStoresItOnMedia(): void
    {
        $video = $this->createTinyVideo();
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Local)
            ->setTitle('Vidéo locale test')
            ->setFilePath(TestImageFactory::publicPathFor($video));

        $thumbnail = $this->generator()->generateForMedia($media);

        self::assertIsString($thumbnail);
        self::assertStringStartsWith('/uploads/media/video-thumbnails/video-locale-test-', $thumbnail);
        self::assertSame($thumbnail, $media->getThumbnailPath());
        $thumbnailFile = TestImageFactory::projectDir().'/public/'.ltrim($thumbnail, '/');
        $this->files[] = $thumbnailFile;
        self::assertFileExists($thumbnailFile);
        $imageSize = getimagesize($thumbnailFile);
        self::assertIsArray($imageSize);
        self::assertSame('image/jpeg', $imageSize['mime']);
    }

    public function testLocalInvalidVideoReturnsNullAndDoesNotKeepFailedThumbnail(): void
    {
        $invalidVideo = TestImageFactory::publicMediaDirectory().'/invalid-video-'.bin2hex(random_bytes(4)).'.mp4';
        file_put_contents($invalidVideo, 'not a video');
        $this->files[] = $invalidVideo;

        $thumbnail = $this->generator()->generateFromPublicPath(TestImageFactory::publicPathFor($invalidVideo), 'Invalid Video.mp4');

        self::assertNull($thumbnail);
        $expectedThumbnail = TestImageFactory::projectDir().'/public/uploads/media/video-thumbnails/invalid-video-'
            .substr(sha1(TestImageFactory::publicPathFor($invalidVideo)), 0, 10)
            .'-thumb.jpg';
        self::assertFileDoesNotExist($expectedThumbnail);
    }

    private function generator(): VideoThumbnailGenerator
    {
        $generator = $this->service(VideoThumbnailGenerator::class);
        self::assertInstanceOf(VideoThumbnailGenerator::class, $generator);

        return $generator;
    }

    private function createTinyVideo(): string
    {
        if (!is_executable('/usr/bin/ffmpeg')) {
            self::markTestSkipped('ffmpeg is required for local video thumbnail integration tests.');
        }

        $file = TestImageFactory::publicMediaDirectory().'/video-thumb-source-'.bin2hex(random_bytes(4)).'.mp4';
        $process = new Process([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'color=c=blue:s=32x18:d=1',
            '-pix_fmt',
            'yuv420p',
            $file,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful() || !is_file($file)) {
            self::markTestSkipped('ffmpeg could not create the local video fixture.');
        }

        $this->files[] = $file;

        return $file;
    }
}
