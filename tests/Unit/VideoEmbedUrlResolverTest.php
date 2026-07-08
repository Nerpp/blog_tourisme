<?php

namespace App\Tests\Unit;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\VideoEmbedUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoEmbedUrlResolverTest extends TestCase
{
    #[DataProvider('supportedUrls')]
    public function testResolvesSupportedVideoUrls(VideoType $type, string $url, string $expectedEmbedUrl): void
    {
        $media = $this->video($type, $url);

        self::assertSame($expectedEmbedUrl, (new VideoEmbedUrlResolver())->resolve($media));
    }

    #[DataProvider('unsupportedUrls')]
    public function testReturnsNullForUnsupportedOrInvalidUrls(?VideoType $type, ?string $url): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType($type)
            ->setExternalUrl($url);

        self::assertNull((new VideoEmbedUrlResolver())->resolve($media));
    }

    /** @return iterable<string, array{VideoType, string, string}> */
    public static function supportedUrls(): iterable
    {
        yield 'youtube watch' => [
            VideoType::Youtube,
            'https://www.youtube.com/watch?v=abcDEF_1234&t=10',
            'https://www.youtube-nocookie.com/embed/abcDEF_1234',
        ];
        yield 'youtube short host' => [
            VideoType::Youtube,
            'https://youtu.be/abcDEF_1234?si=anything',
            'https://www.youtube-nocookie.com/embed/abcDEF_1234',
        ];
        yield 'youtube shorts' => [
            VideoType::Youtube,
            'https://youtube.com/shorts/abcDEF_1234',
            'https://www.youtube-nocookie.com/embed/abcDEF_1234',
        ];
        yield 'youtube live nocookie' => [
            VideoType::Youtube,
            'https://www.youtube-nocookie.com/live/abcDEF_1234',
            'https://www.youtube-nocookie.com/embed/abcDEF_1234',
        ];
        yield 'vimeo' => [
            VideoType::Vimeo,
            'https://vimeo.com/123456789',
            'https://player.vimeo.com/video/123456789',
        ];
        yield 'vimeo video path' => [
            VideoType::Vimeo,
            'https://player.vimeo.com/video/123456789',
            'https://player.vimeo.com/video/123456789',
        ];
        yield 'dailymotion' => [
            VideoType::Dailymotion,
            'https://www.dailymotion.com/video/x8test',
            'https://www.dailymotion.com/embed/video/x8test',
        ];
        yield 'dailymotion short host' => [
            VideoType::Dailymotion,
            'https://dai.ly/x8test',
            'https://www.dailymotion.com/embed/video/x8test',
        ];
    }

    /** @return iterable<string, array{VideoType|null, string|null}> */
    public static function unsupportedUrls(): iterable
    {
        yield 'missing type' => [null, 'https://www.youtube.com/watch?v=abcDEF_1234'];
        yield 'missing url' => [VideoType::Youtube, null];
        yield 'empty url' => [VideoType::Youtube, ''];
        yield 'external type' => [VideoType::External, 'https://video.example.test/watch/123'];
        yield 'local type' => [VideoType::Local, '/uploads/videos/local.mp4'];
        yield 'not a url' => [VideoType::Youtube, 'not-a-url'];
        yield 'youtube missing id' => [VideoType::Youtube, 'https://www.youtube.com/watch?t=10'];
        yield 'youtube invalid id' => [VideoType::Youtube, 'https://www.youtube.com/watch?v=bad!'];
        yield 'youtube array id' => [VideoType::Youtube, 'https://www.youtube.com/watch?v[]=abcDEF_1234'];
        yield 'vimeo invalid' => [VideoType::Vimeo, 'https://vimeo.com/not-numeric'];
        yield 'dailymotion invalid' => [VideoType::Dailymotion, 'https://www.dailymotion.com/embed/x8test'];
    }

    private function video(VideoType $type, string $url): MediaAsset
    {
        return (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType($type)
            ->setExternalUrl($url);
    }
}
