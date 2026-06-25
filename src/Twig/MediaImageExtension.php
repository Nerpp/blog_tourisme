<?php

namespace App\Twig;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaSeoTextService;
use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MediaImageExtension extends AbstractExtension
{
    private const STANDARD_RESPONSIVE_SIZES = ['thumb', 'mobile', 'medium', 'large'];
    private const LEGACY_RESPONSIVE_SIZES = ['thumb', 'medium', 'large'];
    private const IMAGE_PLACEHOLDER = '/images/placeholders/destination-card-placeholder.webp';

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

        if ($this->isStandardImage($media)) {
            return $this->toPublicUrl(
                $this->variantPath($media->getVariants(), $size, 'webp')
                    ?? $media->getThumbnailPath()
                    ?? $media->getExternalUrl()
                    ?? self::IMAGE_PLACEHOLDER,
            );
        }

        return $this->toPublicUrl(
            $this->variantPath($media->getVariants(), $size, 'fallback')
                ?? $media->getThumbnailPath()
                ?? $media->getExternalUrl()
                ?? $this->specialImageFilePath($media)
                ?? self::IMAGE_PLACEHOLDER,
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
                ?? $this->specialImageFilePath($media)
                ?? $media->getExternalUrl()
                ?? $media->getThumbnailPath()
                ?? self::IMAGE_PLACEHOLDER,
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

        $isStandardImage = $this->isStandardImage($media);
        if ($isStandardImage && $format !== 'webp') {
            return null;
        }

        $entries = [];
        $seenWidths = [];
        $sizes = $isStandardImage ? self::STANDARD_RESPONSIVE_SIZES : self::LEGACY_RESPONSIVE_SIZES;
        foreach ($sizes as $size) {
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

    /** @param array<array-key, mixed>|null $variants */
    private function variantPath(?array $variants, string $size, string $format): ?string
    {
        if (!isset($variants[$size]) || !is_array($variants[$size])) {
            return null;
        }

        $path = $variants[$size][$format] ?? null;

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    /**
     * @param array<array-key, mixed>|null $variants
     *
     * @return array<array-key, mixed>
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

    private function specialImageFilePath(MediaAsset $media): ?string
    {
        if ($media->getMediaType() !== MediaType::Image) {
            return null;
        }

        $imageType = $media->getImageType();
        if ($imageType === null || $imageType->value === 'standard') {
            return null;
        }

        return $media->getFilePath();
    }

    private function isStandardImage(MediaAsset $media): bool
    {
        return $media->getMediaType() === MediaType::Image
            && $media->getImageType() === ImageType::Standard;
    }
}
