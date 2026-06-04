<?php

namespace App\Tests\Unit;

use App\Service\VideoThumbnailResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoThumbnailResolverTest extends TestCase
{
    #[DataProvider('youtubeUrls')]
    public function testExtractsYoutubeIdFromSupportedUrls(string $url, string $expectedId): void
    {
        $resolver = new VideoThumbnailResolver();

        self::assertSame($expectedId, $resolver->extractYoutubeId($url));
        self::assertSame(
            sprintf('https://img.youtube.com/vi/%s/hqdefault.jpg', $expectedId),
            $resolver->getThumbnailUrl($url),
        );
    }

    public function testRejectsMissingInvalidAndNonYoutubeUrls(): void
    {
        $resolver = new VideoThumbnailResolver();

        self::assertNull($resolver->getThumbnailUrl(null));
        self::assertNull($resolver->getThumbnailUrl('   '));
        self::assertNull($resolver->getThumbnailUrl('https://example.test/watch?v=abcdef'));
        self::assertNull($resolver->getThumbnailUrl('https://youtube.com/watch?v=bad!id'));
        self::assertNull($resolver->getThumbnailUrl('not-a-url'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function youtubeUrls(): iterable
    {
        yield 'short' => ['https://youtu.be/abcDEF_1234', 'abcDEF_1234'];
        yield 'watch' => ['https://www.youtube.com/watch?v=abcDEF_1234&t=10', 'abcDEF_1234'];
        yield 'embed' => ['https://youtube-nocookie.com/embed/abcDEF_1234', 'abcDEF_1234'];
        yield 'shorts' => ['https://youtube.com/shorts/abcDEF_1234', 'abcDEF_1234'];
        yield 'live' => ['https://youtube.com/live/abcDEF_1234', 'abcDEF_1234'];
    }
}
