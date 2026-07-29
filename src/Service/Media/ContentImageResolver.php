<?php

namespace App\Service\Media;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;

final readonly class ContentImageResolver
{
    public const PLACEHOLDER_ASSET = 'images/placeholders/destination-card-placeholder.webp';
    public const PLACEHOLDER_WIDTH = 2200;
    public const PLACEHOLDER_HEIGHT = 1238;
    /** @var array<int, string> */
    public const PLACEHOLDER_VARIANTS = [
        480 => 'images/placeholders/destination-card-placeholder-480.webp',
        960 => 'images/placeholders/destination-card-placeholder-960.webp',
        1600 => 'images/placeholders/destination-card-placeholder-1600.webp',
    ];

    public function resolve(
        object $content,
        bool $allowGalleryFallback = true,
        bool $standardOnly = false,
    ): ?MediaAsset {
        return match (true) {
            $content instanceof Article => $this->resolveFromLinks(
                $content->getMediaLinks(),
                $content->getFeaturedImage(),
                $allowGalleryFallback,
                $standardOnly,
            ),
            $content instanceof Place => $this->resolveFromLinks(
                $content->getMediaLinks(),
                $content->getFeaturedImage(),
                $allowGalleryFallback,
                $standardOnly,
            ),
            $content instanceof HikeDraft => $this->resolveFromLinks(
                $content->getMediaLinks(),
                allowGalleryFallback: $allowGalleryFallback,
                standardOnly: $standardOnly,
            ),
            $content instanceof CityVisitDraft => $this->resolveFromLinks(
                $content->getMediaLinks(),
                allowGalleryFallback: $allowGalleryFallback,
                standardOnly: $standardOnly,
            ),
            default => null,
        };
    }

    /** @param iterable<object> $mediaLinks */
    public function resolveFromLinks(
        iterable $mediaLinks,
        ?MediaAsset $featuredImage = null,
        bool $allowGalleryFallback = true,
        bool $standardOnly = false,
    ): ?MediaAsset {
        $cover = null;
        $gallery = null;

        foreach ($mediaLinks as $mediaLink) {
            if (!method_exists($mediaLink, 'getMediaAsset') || !method_exists($mediaLink, 'getRole')) {
                continue;
            }

            $media = $mediaLink->getMediaAsset();
            if (!$media instanceof MediaAsset || !$this->isImage($media, $standardOnly)) {
                continue;
            }

            $role = $mediaLink->getRole();
            if ($cover === null && $role === MediaRole::Cover) {
                $cover = $media;
            }

            if (
                $allowGalleryFallback
                && $gallery === null
                && $role === MediaRole::Gallery
                && $this->isRenderableImage($media, $standardOnly)
            ) {
                $gallery = $media;
            }
        }

        if ($cover instanceof MediaAsset) {
            return $cover;
        }

        if ($featuredImage instanceof MediaAsset && $this->isImage($featuredImage, $standardOnly)) {
            return $featuredImage;
        }

        return $allowGalleryFallback ? $gallery : null;
    }

    private function isRenderableImage(MediaAsset $media, bool $standardOnly): bool
    {
        if (!$this->isImage($media, $standardOnly)) {
            return false;
        }

        if ($this->hasPath($media->getThumbnailPath()) || $this->hasPath($media->getExternalUrl())) {
            return true;
        }

        foreach ($media->getVariants() ?? [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            foreach (['webp', 'fallback', 'avif'] as $format) {
                if ($this->hasPath($variant[$format] ?? null)) {
                    return true;
                }
            }
        }

        return $media->getImageType() !== ImageType::Standard
            && $media->getImageType() !== null
            && $this->hasPath($media->getFilePath());
    }

    private function isImage(MediaAsset $media, bool $standardOnly): bool
    {
        return $media->getMediaType() === MediaType::Image
            && (!$standardOnly || $media->getImageType() === ImageType::Standard);
    }

    private function hasPath(mixed $path): bool
    {
        return is_string($path) && trim($path) !== '';
    }
}
