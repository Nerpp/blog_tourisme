<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @phpstan-import-type MediaVariantData from MediaAsset
 * @phpstan-import-type MediaVariantValue from MediaAsset
 * @phpstan-import-type MediaVariants from MediaAsset
 */
final class MediaVariantService
{
    private const PUBLIC_POSTER_DIRECTORY = '/uploads/media/posters';

    public function __construct(
        private readonly ImageVariantGenerator $imageVariantGenerator,
        private readonly StandardLegacyVariantCleanupService $standardLegacyVariantCleanupService,
        private readonly LoggerInterface $logger,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /** @return list<string> */
    public function supportedOutputFormats(): array
    {
        return $this->imageVariantGenerator->supportedOutputFormats();
    }

    /** @return list<string> */
    public function standardOutputFormats(): array
    {
        return $this->imageVariantGenerator->standardOutputFormats();
    }

    public function supportsAvif(): bool
    {
        return $this->imageVariantGenerator->supportsAvif();
    }

    public function supportsWebp(): bool
    {
        return $this->imageVariantGenerator->supportsWebp();
    }

    public function supports(MediaAsset $media): bool
    {
        return match ($media->getMediaType()) {
            MediaType::Image => $media->getFilePath() !== null
                && $this->isLocalPublicPath($media->getFilePath())
                && (
                    $media->getMimeType() === null
                    || $this->imageVariantGenerator->supportsMimeType($media->getMimeType())
                ),
            MediaType::Video => $media->getThumbnailPath() !== null
                && $this->isLocalPublicPath($media->getThumbnailPath()),
        };
    }

    public function hasUsableVariants(MediaAsset $media): bool
    {
        $variants = $this->normalizeVariants($media->getVariants());
        if ($variants === []) {
            return false;
        }

        if ($media->getMediaType() === MediaType::Video) {
            return isset($variants['poster']) && is_array($variants['poster']);
        }

        if ($media->getImageType() === ImageType::Standard) {
            foreach (['thumb', 'mobile', 'medium', 'large'] as $size) {
                $variant = $variants[$size] ?? null;
                if (!is_array($variant) || !is_string($variant['webp'] ?? null) || $variant['webp'] === '') {
                    return false;
                }
            }

            return true;
        }

        return isset($variants['thumb'], $variants['medium'], $variants['large']);
    }

    /**
     * @return array{status: string, generated: bool, message: string|null}
     */
    public function generateForMedia(MediaAsset $media, bool $force = false): array
    {
        if (!$this->supports($media)) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Média local image non supporté ou média externe.',
            ];
        }

        if (!$force && $this->hasUsableVariants($media)) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Variantes déjà présentes.',
            ];
        }

        try {
            if ($media->getMediaType() === MediaType::Video) {
                return $this->generatePosterVariants($media);
            }

            return $this->generateImageVariants($media);
        } catch (\Throwable $exception) {
            $this->logger->warning('La génération de variantes média a échoué.', [
                'mediaId' => $media->getId(),
                'filePath' => $media->getFilePath(),
                'thumbnailPath' => $media->getThumbnailPath(),
                'absolutePath' => $this->absolutePath($this->sourcePath($media)),
                'exception' => $exception,
            ]);

            return [
                'status' => 'error',
                'generated' => false,
                'message' => $this->diagnosticMessage($media, $exception->getMessage()),
            ];
        }
    }

    /**
     * @return array{status: string, generated: bool, message: string|null}
     */
    private function generateImageVariants(MediaAsset $media): array
    {
        $filePath = $media->getFilePath();
        if ($filePath === null) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Aucun fichier local image.',
            ];
        }

        if (!$this->localPublicFileExists($filePath)) {
            return [
                'status' => 'error',
                'generated' => false,
                'message' => $this->diagnosticMessage($media, 'Fichier source image introuvable.'),
            ];
        }

        $previousVariants = $media->getVariants();
        $variants = $this->normalizeVariants(
            $media->getImageType() === ImageType::Standard
                ? $this->imageVariantGenerator->generateStandard($filePath, $filePath)
                : $this->imageVariantGenerator->generate($filePath, $filePath),
        );
        $media->setVariants($variants);

        $source = $variants['source'] ?? null;
        if (is_array($source)) {
            $media
                ->setMimeType(is_string($source['mimeType'] ?? null) ? $source['mimeType'] : $media->getMimeType())
                ->setWidth(is_numeric($source['width'] ?? null) ? (int) $source['width'] : $media->getWidth())
                ->setHeight(is_numeric($source['height'] ?? null) ? (int) $source['height'] : $media->getHeight());
        }

        $thumbnailPath = $media->getImageType() === ImageType::Standard
            ? $this->variantPath($variants, 'thumb', 'webp')
            : $this->variantPath($variants, 'thumb', 'fallback');
        if ($thumbnailPath !== null && ($media->getImageType() === ImageType::Standard || $media->getThumbnailPath() === null)) {
            $media->setThumbnailPath($thumbnailPath);
        }

        if ($media->getImageType() === ImageType::Standard) {
            $this->standardLegacyVariantCleanupService->cleanup($media, legacyVariants: $previousVariants);
        }

        return [
            'status' => 'generated',
            'generated' => true,
            'message' => null,
        ];
    }

    /**
     * @return array{status: string, generated: bool, message: string|null}
     */
    private function generatePosterVariants(MediaAsset $media): array
    {
        $thumbnailPath = $media->getThumbnailPath();
        if ($thumbnailPath === null) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Aucun poster manuel.',
            ];
        }

        if (!$this->localPublicFileExists($thumbnailPath)) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => $this->diagnosticMessage($media, 'Vidéo externe avec poster local manquant.'),
            ];
        }

        $existingVariants = $this->normalizeVariants($media->getVariants());
        $existingVariants['poster'] = $this->normalizeVariantData($this->imageVariantGenerator->generate(
            $thumbnailPath,
            $thumbnailPath.'_poster',
            self::PUBLIC_POSTER_DIRECTORY,
        ));
        $media->setVariants($existingVariants);

        return [
            'status' => 'generated',
            'generated' => true,
            'message' => null,
        ];
    }

    private function isLocalPublicPath(string $path): bool
    {
        return !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://');
    }

    private function localPublicFileExists(string $path): bool
    {
        $absolutePath = $this->absolutePath($path);

        return $absolutePath !== null && is_file($absolutePath);
    }

    private function diagnosticMessage(MediaAsset $media, string $message): string
    {
        return sprintf(
            '%s mediaId=%s filePath=%s thumbnailPath=%s chemin absolu résolu=%s',
            $message,
            $media->getId() ?? 'non persisté',
            $media->getFilePath() ?? '-',
            $media->getThumbnailPath() ?? '-',
            $this->absolutePath($this->sourcePath($media)) ?? '-',
        );
    }

    private function sourcePath(MediaAsset $media): ?string
    {
        if ($media->getMediaType() === MediaType::Video) {
            return $media->getThumbnailPath();
        }

        return $media->getFilePath();
    }

    private function absolutePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (!$this->isLocalPublicPath($path)) {
            return 'URL externe';
        }

        return rtrim($this->parameterBag->get('kernel.project_dir'), '/').'/public/'.ltrim($path, '/');
    }

    /**
     * @return MediaVariants
     */
    private function normalizeVariants(mixed $variants): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $normalized = [];
        foreach ($variants as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($value === null || is_scalar($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalizeVariantData($value);
            }
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return MediaVariantData
     */
    private function normalizeVariantData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $result = $this->normalizeVariantValue($value);
            if ($result['valid']) {
                $normalized[$key] = $result['value'];
            }
        }

        return $normalized;
    }

    /**
     * @return array{
     *     valid: bool,
     *     value: MediaVariantValue|array<string, MediaVariantValue>
     * }
     */
    private function normalizeVariantValue(mixed $value): array
    {
        if ($value === null || is_scalar($value)) {
            return ['valid' => true, 'value' => $value];
        }

        if (!is_array($value)) {
            return ['valid' => false, 'value' => null];
        }

        if (array_is_list($value)) {
            $normalizedList = $this->normalizeStringList($value);

            return $normalizedList === null
                ? ['valid' => false, 'value' => null]
                : ['valid' => true, 'value' => $normalizedList];
        }

        $normalized = [];
        foreach ($value as $key => $nestedValue) {
            if (!is_string($key)) {
                continue;
            }

            if ($nestedValue === null || is_scalar($nestedValue)) {
                $normalized[$key] = $nestedValue;
                continue;
            }

            if (is_array($nestedValue) && array_is_list($nestedValue)) {
                $normalizedList = $this->normalizeStringList($nestedValue);
                if ($normalizedList !== null) {
                    $normalized[$key] = $normalizedList;
                }
            }
        }

        return ['valid' => true, 'value' => $normalized];
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>|null
     */
    private function normalizeStringList(array $values): ?array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                return null;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param MediaVariants $variants
     */
    private function variantPath(array $variants, string $group, string $format): ?string
    {
        $variant = $variants[$group] ?? null;
        if (!is_array($variant)) {
            return null;
        }

        $path = $variant[$format] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
