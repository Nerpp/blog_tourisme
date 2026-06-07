<?php

namespace App\Twig;

use App\Entity\MediaAsset;
use App\Service\Media\MediaSeoTextService;
use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @phpstan-type MediaVariantValue scalar|list<string>|null
 * @phpstan-type MediaVariantData array<string, MediaVariantValue|array<string, MediaVariantValue>>
 * @phpstan-type MediaVariants array<string, MediaVariantData|scalar|null>
 */
final class MediaImageExtension extends AbstractExtension
{
    private const RESPONSIVE_SIZES = ['thumb', 'medium', 'large'];

    public function __construct(
        private readonly Packages $packages,
        private readonly MediaSeoTextService $mediaSeoTextService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_image_url', [$this, 'imageUrl']),
            new TwigFunction('media_modal_url', [$this, 'modalUrl']),
            new TwigFunction('media_poster_url', [$this, 'posterUrl']),
            new TwigFunction('media_image_srcset', [$this, 'imageSrcset']),
            new TwigFunction('media_image_dimensions', [$this, 'imageDimensions']),
            new TwigFunction('media_public_title', [$this, 'publicTitle']),
            new TwigFunction('media_public_alt', [$this, 'publicAlt']),
        ];
    }

    public function publicTitle(?MediaAsset $media, object|string|null $context = null, ?string $fallbackTitle = null): ?string
    {
        if (!$media instanceof MediaAsset) {
            return $fallbackTitle;
        }

        return $this->mediaSeoTextService->publicTitle($media, $context, $fallbackTitle);
    }

    public function publicAlt(?MediaAsset $media, object|string|null $context = null, ?string $fallbackTitle = null): string
    {
        if (!$media instanceof MediaAsset) {
            return $fallbackTitle ?? '';
        }

        return $this->mediaSeoTextService->publicAlt($media, $context, $fallbackTitle);
    }

    public function imageUrl(?MediaAsset $media, string $size = 'thumb'): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        if (in_array($size, ['medium', 'large'], true)) {
            return $this->toPublicUrl(
                $this->variantPath($media->getVariants(), $size, 'fallback')
                    ?? $media->getFilePath()
                    ?? $media->getExternalUrl()
                    ?? $media->getThumbnailPath(),
            );
        }

        return $this->toPublicUrl(
            $this->variantPath($media->getVariants(), $size, 'fallback')
                ?? $media->getThumbnailPath()
                ?? $media->getFilePath()
                ?? $media->getExternalUrl(),
        );
    }

    public function modalUrl(?MediaAsset $media): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        return $this->toPublicUrl(
            $this->variantPath($media->getVariants(), 'large', 'webp')
                ?? $this->variantPath($media->getVariants(), 'large', 'fallback')
                ?? $media->getFilePath()
                ?? $media->getExternalUrl()
                ?? $media->getThumbnailPath(),
        );
    }

    public function posterUrl(?MediaAsset $media, string $size = 'medium'): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        $variants = $media->getVariants();
        $posterVariants = is_array($variants) && isset($variants['poster']) && is_array($variants['poster'])
            ? $variants['poster']
            : null;

        return $this->toPublicUrl(
            $this->variantPath($posterVariants, $size, 'fallback')
                ?? $media->getThumbnailPath(),
        );
    }

    public function imageSrcset(?MediaAsset $media, string $format): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        $entries = [];
        $seenWidths = [];
        foreach (self::RESPONSIVE_SIZES as $size) {
            $variant = $this->variant($media->getVariants(), $size);
            $path = $variant[$format] ?? null;
            $width = $variant['width'] ?? null;

            if (!is_string($path) || !is_numeric($width)) {
                continue;
            }

            $width = (int) $width;
            if (isset($seenWidths[$width])) {
                continue;
            }

            $seenWidths[$width] = true;
            $entries[] = sprintf('%s %dw', $this->toPublicUrl($path), $width);
        }

        return $entries === [] ? null : implode(', ', $entries);
    }

    /** @return array{width: int, height: int}|null */
    public function imageDimensions(?MediaAsset $media, string $size = 'thumb'): ?array
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        $variant = $this->variant($media->getVariants(), $size);
        if (isset($variant['width'], $variant['height']) && is_numeric($variant['width']) && is_numeric($variant['height'])) {
            return [
                'width' => (int) $variant['width'],
                'height' => (int) $variant['height'],
            ];
        }

        $metadata = $media->getMetadata();
        if ($size === 'thumb' && is_array($metadata) && isset($metadata['thumbnailWidth'], $metadata['thumbnailHeight'])) {
            return [
                'width' => (int) $metadata['thumbnailWidth'],
                'height' => (int) $metadata['thumbnailHeight'],
            ];
        }

        if ($media->getWidth() !== null && $media->getHeight() !== null) {
            return [
                'width' => $media->getWidth(),
                'height' => $media->getHeight(),
            ];
        }

        return null;
    }

    /** @param MediaVariants|null $variants */
    private function variantPath(?array $variants, string $size, string $format): ?string
    {
        $variant = $this->variant($variants, $size);
        $path = $variant[$format] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param MediaVariants|null $variants
     *
     * @return MediaVariantData
     */
    private function variant(?array $variants, string $size): array
    {
        if (!is_array($variants) || !isset($variants[$size]) || !is_array($variants[$size])) {
            return [];
        }

        return $variants[$size];
    }

    private function toPublicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $this->packages->getUrl($path);
    }
}
