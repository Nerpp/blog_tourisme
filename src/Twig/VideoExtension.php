<?php

namespace App\Twig;

use App\Entity\MediaAsset;
use App\Service\VideoEmbedUrlResolver;
use App\Service\VideoThumbnailResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class VideoExtension extends AbstractExtension
{
    public function __construct(
        private readonly VideoEmbedUrlResolver $videoEmbedUrlResolver,
        private readonly VideoThumbnailResolver $videoThumbnailResolver,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('video_embed_url', [$this, 'resolveVideoEmbedUrl']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('video_thumbnail_url', [$this, 'resolveVideoThumbnailUrl']),
        ];
    }

    public function resolveVideoEmbedUrl(MediaAsset $media): ?string
    {
        return $this->videoEmbedUrlResolver->resolve($media);
    }

    public function resolveVideoThumbnailUrl(?string $url): ?string
    {
        return $this->videoThumbnailResolver->getThumbnailUrl($url);
    }
}
