<?php

namespace App\Twig;

use App\Entity\MediaAsset;
use App\Service\VideoEmbedUrlResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class VideoExtension extends AbstractExtension
{
    public function __construct(
        private readonly VideoEmbedUrlResolver $videoEmbedUrlResolver,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('video_embed_url', [$this, 'resolveVideoEmbedUrl']),
        ];
    }

    public function resolveVideoEmbedUrl(MediaAsset $media): ?string
    {
        return $this->videoEmbedUrlResolver->resolve($media);
    }
}