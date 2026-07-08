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
    private const LEGACY_RESPONSIVE_SIZES = ['thumb', 'mobile', 'medium', 'large'];
    private const COVER_RESPONSIVE_SIZES = ['thumb', 'mobile', 'medium'];
    private const STANDARD_VARIANT_WIDTHS = [
        'thumb' => 600,
        'mobile' => 960,
        'medium' => 1600,
        'large' => 1920,
        'thumbnail320' => 320,
        'thumbnail480' => 480,
        'content640' => 640,
        'content768' => 768,
        'content960' => 960,
    ];
    private const LEGACY_VARIANT_WIDTHS = [
        'thumb' => 640,
        'mobile' => 960,
        'medium' => 1280,
        'large' => 2560,
    ];
    private const ARTICLE_RESPONSIVE_LONG_SIDES = [
        'thumb' => ['metadata' => 'articleInlineMaxLongSide', 'default' => 640],
        'mobile' => ['metadata' => 'articleDisplayMaxLongSide', 'default' => 960],
        'medium' => ['metadata' => 'articleCoverMaxLongSide', 'default' => 1280],
        'large' => ['metadata' => 'articleSourceMaxLongSide', 'default' => 1600],
    ];
    private const DISPLAY_RESPONSIVE_SIZES = [
        'content' => ['content640', 'content768', 'content960', 'medium', 'large'],
        'thumbnail' => ['thumbnail320', 'thumbnail480', 'thumb', 'mobile'],
    ];
    private const DISPLAY_PREFERRED_SIZES = [
        'content' => ['content768', 'content960', 'content640', 'mobile', 'thumb', 'medium', 'large'],
        'thumbnail' => ['thumbnail480', 'thumbnail320', 'thumb', 'mobile', 'medium'],
    ];
    private const DISPLAY_FALLBACK_SIZES = [
        'content' => ['thumb', 'mobile', 'medium', 'large'],
        'thumbnail' => ['thumb', 'mobile', 'medium'],
    ];
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
            new TwigFunction('media_cover_image_url', [$this, 'coverUrl']),
            new TwigFunction('media_cover_image_srcset', [$this, 'coverSrcset']),
            new TwigFunction('media_cover_image_dimensions', [$this, 'coverDimensions']),
            new TwigFunction('media_display_image_url', [$this, 'displayUrl']),
            new TwigFunction('media_display_image_srcset', [$this, 'displaySrcset']),
            new TwigFunction('media_display_image_dimensions', [$this, 'displayDimensions']),
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
        if ($this->isArticleResponsiveImage($media)) {
            // The larger Article source is intentionally reserved for the click-opened lightbox.
            $sizes = ['thumb', 'mobile', 'medium'];
        }
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

    public function coverUrl(?MediaAsset $media, string $size = 'medium', string $format = 'webp'): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        foreach ($this->coverCandidateSizes($size) as $candidateSize) {
            $path = $this->variantPath($media->getVariants(), $candidateSize, $format);
            if ($path !== null) {
                return $this->toPublicUrl($path);
            }

            if ($format === 'fallback' && $this->isStandardImage($media)) {
                $path = $this->variantPath($media->getVariants(), $candidateSize, 'webp');
                if ($path !== null) {
                    return $this->toPublicUrl($path);
                }
            }
        }

        return $format === 'fallback' ? $this->imageUrl($media, $size) : null;
    }

    public function coverSrcset(?MediaAsset $media, string $format): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        $entries = [];
        $seenWidths = [];
        foreach (self::COVER_RESPONSIVE_SIZES as $size) {
            $variant = $this->variant($media->getVariants(), $size);
            $path = $variant[$format] ?? null;
            if ($format === 'fallback' && $this->isStandardImage($media)) {
                $path = $variant['webp'] ?? null;
            }
            $width = $variant['width'] ?? null;
            if (!is_string($path) || trim($path) === '' || !is_numeric($width)) {
                continue;
            }

            $width = (int) $width;
            if (isset($seenWidths[$width])) {
                continue;
            }

            $seenWidths[$width] = true;
            $entries[] = sprintf('%s %dw', $this->toPublicUrl($path), $width);
        }

        if ($entries === []) {
            $variant = $this->variant($media->getVariants(), 'large');
            $path = $variant[$format] ?? null;
            if ($format === 'fallback' && $this->isStandardImage($media)) {
                $path = $variant['webp'] ?? null;
            }
            if (is_string($path) && trim($path) !== '' && is_numeric($variant['width'] ?? null)) {
                $entries[] = sprintf('%s %dw', $this->toPublicUrl($path), (int) $variant['width']);
            }
        }

        return $entries === [] ? null : implode(', ', $entries);
    }

    /** @return array{width: int, height: int}|null */
    public function coverDimensions(?MediaAsset $media, string $size = 'medium'): ?array
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        foreach ($this->coverCandidateSizes($size) as $candidateSize) {
            $dimensions = $this->variantDimensions($media, $candidateSize);
            if ($dimensions !== null) {
                return $dimensions;
            }
        }

        return $this->imageDimensions($media, $size);
    }

    public function displayUrl(?MediaAsset $media, string $profile = 'content'): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        if (!$this->isStandardImage($media)) {
            return $this->imageUrl($media, $profile === 'thumbnail' ? 'thumb' : 'mobile');
        }

        foreach ($this->displayPreferredSizes($profile) as $size) {
            $path = $this->variantPath($media->getVariants(), $size, 'webp');
            if ($path !== null) {
                return $this->toPublicUrl($path);
            }
        }

        return $this->imageUrl($media, $profile === 'thumbnail' ? 'thumb' : 'mobile');
    }

    public function displaySrcset(?MediaAsset $media, string $profile = 'content'): ?string
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        if (!$this->isStandardImage($media)) {
            return $this->imageSrcset($media, 'webp');
        }

        if ($this->isArticleResponsiveImage($media)) {
            return $this->imageSrcset($media, 'webp');
        }

        $entries = [];
        $seenWidths = [];
        $sizes = $this->displayResponsiveSizes($profile);
        $semanticPrefix = $profile === 'thumbnail' ? 'thumbnail' : 'content';
        $hasProfileVariant = array_any(
            $sizes,
            fn (string $size): bool => str_starts_with($size, $semanticPrefix)
                && $this->variantPath($media->getVariants(), $size, 'webp') !== null,
        );
        if (!$hasProfileVariant) {
            $sizes = self::DISPLAY_FALLBACK_SIZES[$profile] ?? self::DISPLAY_FALLBACK_SIZES['content'];
        }

        foreach ($sizes as $size) {
            $variant = $this->variant($media->getVariants(), $size);
            $path = $variant['webp'] ?? null;
            $width = $variant['width'] ?? null;
            if (!is_string($path) || trim($path) === '' || !is_numeric($width)) {
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
    public function displayDimensions(?MediaAsset $media, string $profile = 'content'): ?array
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        foreach ($this->displayPreferredSizes($profile) as $size) {
            $dimensions = $this->variantDimensions($media, $size);
            if ($dimensions !== null) {
                return $dimensions;
            }
        }

        return $this->imageDimensions($media, $profile === 'thumbnail' ? 'thumb' : 'mobile');
    }

    /** @return array{width: int, height: int}|null */
    public function imageDimensions(?MediaAsset $media, string $size = 'thumb'): ?array
    {
        if (!$media instanceof MediaAsset) {
            return null;
        }

        $dimensions = $this->variantDimensions($media, $size);
        if ($dimensions !== null) {
            return $dimensions;
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

    /** @return array{width: int, height: int}|null */
    private function variantDimensions(MediaAsset $media, string $size): ?array
    {
        $variant = $this->variant($media->getVariants(), $size);
        if (isset($variant['width'], $variant['height']) && is_numeric($variant['width']) && is_numeric($variant['height'])) {
            return [
                'width' => (int) $variant['width'],
                'height' => (int) $variant['height'],
            ];
        }

        if ($this->variantHasRenderablePath($variant)) {
            return $this->inferVariantDimensions($media, $size);
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

    /** @param array<array-key, mixed> $variant */
    private function variantHasRenderablePath(array $variant): bool
    {
        foreach (['webp', 'fallback', 'avif'] as $format) {
            $path = $variant[$format] ?? null;
            if (is_string($path) && trim($path) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array{width: int, height: int}|null */
    private function inferVariantDimensions(MediaAsset $media, string $size): ?array
    {
        $sourceWidth = $media->getWidth();
        $sourceHeight = $media->getHeight();
        if ($sourceWidth === null || $sourceHeight === null || $sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        if ($this->isArticleResponsiveImage($media)) {
            $longSide = $this->articleResponsiveLongSide($media, $size);
            if ($longSide === null) {
                return null;
            }

            $scale = min(1.0, $longSide / max($sourceWidth, $sourceHeight));

            return [
                'width' => max(1, (int) round($sourceWidth * $scale)),
                'height' => max(1, (int) round($sourceHeight * $scale)),
            ];
        }

        $targetWidth = $this->targetVariantWidth($media, $size);
        if ($targetWidth === null) {
            return null;
        }

        $width = min($sourceWidth, $targetWidth);

        return [
            'width' => $width,
            'height' => max(1, (int) round($sourceHeight * ($width / $sourceWidth))),
        ];
    }

    private function articleResponsiveLongSide(MediaAsset $media, string $size): ?int
    {
        $configuration = self::ARTICLE_RESPONSIVE_LONG_SIDES[$size] ?? null;
        if ($configuration === null) {
            return null;
        }

        $metadata = $media->getMetadata();
        $metadataValue = is_array($metadata) ? ($metadata[$configuration['metadata']] ?? null) : null;
        if (is_numeric($metadataValue) && (int) $metadataValue > 0) {
            return (int) $metadataValue;
        }

        return $configuration['default'];
    }

    private function targetVariantWidth(MediaAsset $media, string $size): ?int
    {
        if ($this->isStandardImage($media)) {
            return self::STANDARD_VARIANT_WIDTHS[$size] ?? null;
        }

        return self::LEGACY_VARIANT_WIDTHS[$size] ?? null;
    }

    /** @return list<string> */
    private function coverCandidateSizes(string $size): array
    {
        return match ($size) {
            'thumb' => ['thumb', 'mobile', 'medium', 'large'],
            'mobile' => ['mobile', 'thumb', 'medium', 'large'],
            'large' => ['large', 'medium', 'mobile', 'thumb'],
            default => ['medium', 'mobile', 'thumb', 'large'],
        };
    }

    /** @return list<string> */
    private function displayResponsiveSizes(string $profile): array
    {
        return self::DISPLAY_RESPONSIVE_SIZES[$profile] ?? self::DISPLAY_RESPONSIVE_SIZES['content'];
    }

    /** @return list<string> */
    private function displayPreferredSizes(string $profile): array
    {
        return self::DISPLAY_PREFERRED_SIZES[$profile] ?? self::DISPLAY_PREFERRED_SIZES['content'];
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

    private function isArticleResponsiveImage(MediaAsset $media): bool
    {
        $metadata = $media->getMetadata();

        return $this->isStandardImage($media)
            && is_array($metadata)
            && ($metadata['articleResponsiveWebp'] ?? false) === true;
    }
}
