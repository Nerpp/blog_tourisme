<?php

namespace App\Twig;

use App\Entity\MediaAsset;
use App\Service\Media\ContentImageResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentImageExtension extends AbstractExtension
{
    public function __construct(private readonly ContentImageResolver $contentImageResolver)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('content_image_media', [$this, 'resolve']),
        ];
    }

    public function resolve(object $content, bool $allowGalleryFallback = true): ?MediaAsset
    {
        return $this->contentImageResolver->resolve($content, $allowGalleryFallback);
    }
}
