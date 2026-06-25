<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @phpstan-import-type MediaVariants from MediaAsset
 *
 * @phpstan-type LegacyVariantFileReport array{
 *     path: string,
 *     bytes: int,
 *     deleted: bool,
 *     missing: bool,
 *     reason: string|null
 * }
 * @phpstan-type LegacyVariantCleanupReport array{
 *     skipped: bool,
 *     dryRun: bool,
 *     reason: string|null,
 *     files: list<LegacyVariantFileReport>,
 *     bytes: int,
 *     metadataChanged: bool
 * }
 */
final class StandardLegacyVariantCleanupService
{
    private const PUBLIC_VARIANT_DIRECTORY = '/uploads/media/variants/';
    private const STANDARD_SIZES = ['thumb', 'mobile', 'medium', 'large'];
    private const LEGACY_EXTENSIONS = ['jpg', 'jpeg', 'png', 'avif'];

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $legacyVariants
     *
     * @return LegacyVariantCleanupReport
     */
    public function cleanup(
        MediaAsset $media,
        bool $dryRun = false,
        ?array $legacyVariants = null,
        bool $pruneMetadata = false,
    ): array {
        if (!$this->isStandardImage($media)) {
            return $this->skipped($dryRun, 'média non standard');
        }

        $currentVariants = $this->variantsArray($media->getVariants());
        $activeValidation = $this->activeStandardWebpPaths($currentVariants);
        if (!$activeValidation['valid']) {
            return $this->skipped($dryRun, $activeValidation['reason'] ?? 'variantes WebP actives invalides');
        }

        $activePaths = array_fill_keys($activeValidation['paths'], true);
        $legacyPaths = $this->legacyVariantPaths($this->variantsArray($legacyVariants ?? $media->getVariants()));
        $candidatePaths = [];
        foreach ($legacyPaths as $path) {
            if (!isset($activePaths[$path])) {
                $candidatePaths[] = $path;
            }
        }

        $files = [];
        $bytes = 0;
        $hasDeletionFailure = false;
        foreach ($candidatePaths as $path) {
            $absolutePath = $this->absoluteVariantPath($path);
            if ($absolutePath === null) {
                $files[] = $this->fileReport($path, 0, false, false, 'chemin ignoré');
                $hasDeletionFailure = true;

                continue;
            }

            if (!is_file($absolutePath)) {
                $files[] = $this->fileReport($path, 0, false, true, 'fichier absent');

                continue;
            }

            $fileBytes = (int) (filesize($absolutePath) ?: 0);
            $bytes += $fileBytes;
            if ($dryRun) {
                $files[] = $this->fileReport($path, $fileBytes, false, false, null);

                continue;
            }

            if (!@unlink($absolutePath)) {
                $files[] = $this->fileReport($path, $fileBytes, false, false, 'suppression impossible');
                $hasDeletionFailure = true;
                $this->logger->warning('Impossible de supprimer une variante héritée standard.', [
                    'mediaId' => $media->getId(),
                    'path' => $path,
                ]);

                continue;
            }

            $files[] = $this->fileReport($path, $fileBytes, true, false, null);
        }

        $cleanedVariants = $this->standardWebpOnlyVariants($currentVariants);
        $metadataChanged = $pruneMetadata && $cleanedVariants !== $currentVariants;
        if ($metadataChanged && !$dryRun && !$hasDeletionFailure) {
            $media->setVariants($cleanedVariants);
        }

        if ($files === [] && !$metadataChanged) {
            return $this->skipped($dryRun, 'aucune variante héritée non-WebP');
        }

        return [
            'skipped' => false,
            'dryRun' => $dryRun,
            'reason' => $hasDeletionFailure ? 'certains fichiers n’ont pas pu être supprimés' : null,
            'files' => $files,
            'bytes' => $bytes,
            'metadataChanged' => $metadataChanged && ($dryRun || !$hasDeletionFailure),
        ];
    }

    private function isStandardImage(MediaAsset $media): bool
    {
        return $media->getMediaType() === MediaType::Image
            && $media->getImageType() === ImageType::Standard;
    }

    /**
     * @param array<string, mixed> $variants
     *
     * @return array{valid: bool, reason: string|null, paths: list<string>}
     */
    private function activeStandardWebpPaths(array $variants): array
    {
        $paths = [];
        foreach (self::STANDARD_SIZES as $size) {
            $variant = $variants[$size] ?? null;
            if (!is_array($variant)) {
                return ['valid' => false, 'reason' => sprintf('variante WebP active %s absente', $size), 'paths' => []];
            }

            $path = $variant['webp'] ?? null;
            if (!is_string($path) || $path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'webp') {
                return ['valid' => false, 'reason' => sprintf('chemin WebP actif %s absent', $size), 'paths' => []];
            }

            $absolutePath = $this->absoluteVariantPath($path);
            if ($absolutePath === null || !is_file($absolutePath) || !is_readable($absolutePath)) {
                return ['valid' => false, 'reason' => sprintf('fichier WebP actif %s absent ou illisible', $size), 'paths' => []];
            }

            $paths[] = $path;
        }

        return ['valid' => true, 'reason' => null, 'paths' => array_values(array_unique($paths))];
    }

    /**
     * @param array<string, mixed> $variants
     *
     * @return list<string>
     */
    private function legacyVariantPaths(array $variants): array
    {
        $paths = [];
        foreach ($variants as $group => $variant) {
            if ($group === 'source') {
                continue;
            }

            $this->collectLegacyPaths($variant, $paths);
        }

        return array_values(array_unique($paths));
    }

    /** @param list<string> $paths */
    private function collectLegacyPaths(mixed $value, array &$paths): void
    {
        if (is_string($value)) {
            if ($this->isLegacyVariantPath($value)) {
                $paths[] = $value;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $nestedValue) {
            $this->collectLegacyPaths($nestedValue, $paths);
        }
    }

    private function isLegacyVariantPath(string $path): bool
    {
        if (!str_starts_with($path, self::PUBLIC_VARIANT_DIRECTORY)) {
            return false;
        }

        $relative = substr($path, strlen(self::PUBLIC_VARIANT_DIRECTORY));
        if ($relative === '' || str_contains($relative, '/')) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::LEGACY_EXTENSIONS, true);
    }

    /**
     * @param array<string, mixed> $variants
     *
     * @return MediaVariants
     */
    private function standardWebpOnlyVariants(array $variants): array
    {
        $cleaned = [];
        $source = $variants['source'] ?? null;
        if (is_array($source)) {
            $cleanedSource = [];
            foreach (['path', 'mimeType', 'width', 'height', 'generatedAt'] as $key) {
                if (array_key_exists($key, $source) && (is_scalar($source[$key]) || $source[$key] === null)) {
                    $cleanedSource[$key] = $source[$key];
                }
            }
            $cleanedSource['formats'] = ['webp'];
            $cleaned['source'] = $cleanedSource;
        }

        foreach (self::STANDARD_SIZES as $size) {
            $variant = $variants[$size] ?? null;
            if (!is_array($variant)) {
                continue;
            }

            $webp = $variant['webp'] ?? null;
            $width = $variant['width'] ?? null;
            $height = $variant['height'] ?? null;
            if (!is_string($webp) || !is_numeric($width) || !is_numeric($height)) {
                continue;
            }

            $cleaned[$size] = [
                'webp' => $webp,
                'width' => (int) $width,
                'height' => (int) $height,
            ];
        }

        return $cleaned;
    }

    private function absoluteVariantPath(string $path): ?string
    {
        if (
            str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)
            || !str_starts_with($path, self::PUBLIC_VARIANT_DIRECTORY)
        ) {
            return null;
        }

        $relative = substr($path, strlen(self::PUBLIC_VARIANT_DIRECTORY));
        if ($relative === '' || str_contains($relative, '/')) {
            return null;
        }

        $publicDirectory = rtrim($this->parameterBag->get('kernel.project_dir'), '/').'/public';
        $variantDirectory = $publicDirectory.rtrim(self::PUBLIC_VARIANT_DIRECTORY, '/');
        $realVariantDirectory = realpath($variantDirectory);
        if ($realVariantDirectory === false) {
            return null;
        }

        $absolutePath = $publicDirectory.$path;
        if (is_file($absolutePath)) {
            $realPath = realpath($absolutePath);

            return $realPath !== false && str_starts_with($realPath, rtrim($realVariantDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                ? $realPath
                : null;
        }

        return $absolutePath;
    }

    /**
     * @param array<array-key, mixed>|null $variants
     *
     * @return array<string, mixed>
     */
    private function variantsArray(?array $variants): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $normalized = [];
        foreach ($variants as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return LegacyVariantCleanupReport
     */
    private function skipped(bool $dryRun, string $reason): array
    {
        return [
            'skipped' => true,
            'dryRun' => $dryRun,
            'reason' => $reason,
            'files' => [],
            'bytes' => 0,
            'metadataChanged' => false,
        ];
    }

    /**
     * @return LegacyVariantFileReport
     */
    private function fileReport(string $path, int $bytes, bool $deleted, bool $missing, ?string $reason): array
    {
        return [
            'path' => $path,
            'bytes' => $bytes,
            'deleted' => $deleted,
            'missing' => $missing,
            'reason' => $reason,
        ];
    }
}
