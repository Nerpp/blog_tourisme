<?php

namespace App\Tests\Unit\Twig;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\VideoEmbedUrlResolver;
use App\Service\VideoThumbnailResolver;
use App\Twig\VideoExtension;
use PHPUnit\Framework\TestCase;

final class VideoExtensionTest extends TestCase
{
    public function testRegistersExpectedTwigFilterAndFunction(): void
    {
        $extension = $this->extension();

        self::assertSame('video_embed_url', $extension->getFilters()[0]->getName());
        self::assertSame('video_thumbnail_url', $extension->getFunctions()[0]->getName());
    }

    public function testDelegatesEmbedAndThumbnailResolution(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://youtu.be/abcDEF_1234');
        $extension = $this->extension();

        self::assertSame('https://www.youtube-nocookie.com/embed/abcDEF_1234', $extension->resolveVideoEmbedUrl($media));
        self::assertSame('https://img.youtube.com/vi/abcDEF_1234/hqdefault.jpg', $extension->resolveVideoThumbnailUrl('https://youtu.be/abcDEF_1234'));
        self::assertNull($extension->resolveVideoThumbnailUrl(null));
    }

    private function extension(): VideoExtension
    {
        return new VideoExtension(new VideoEmbedUrlResolver(), new VideoThumbnailResolver());
    }
}
