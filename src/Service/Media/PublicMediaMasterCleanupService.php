<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class PublicMediaMasterCleanupService
{
    private const CRITICAL_SIZES = ['thumb', 'mobile', 'medium', 'large'];

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isClassicImage(MediaAsset $media): bool
    {
        return $media->getMediaType() === MediaType::Image
            && $media->getImageType() === ImageType::Standard;
    }

    /** @return array{valid: bool, reason: ?string} */
    public function validateCriticalVariants(MediaAsset $media): array
    {
        $variants = $media->getVariants();
        if (!is_array($variants)) {
            return ['valid' => false, 'reason' => 'aucune variante enregistrée'];
        }

        foreach (self::CRITICAL_SIZES as $size) {
            if (!isset($variants[$size]) || !is_array($variants[$size])) {
                return ['valid' => false, 'reason' => sprintf('variante %s absente', $size)];
            }

            $variant = $variants[$size];
            if (!$this->publicWebpFileIsReadable($variant['webp'] ?? null)) {
                return ['valid' => false, 'reason' => sprintf('WebP %s absent ou illisible', $size)];
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * @return array{
     *     deleted: bool,
     *     skipped: bool,
     *     dryRun: bool,
     *     reason: ?string,
     *     path: ?string,
     *     bytes: int
     * }
     */
    public function cleanupIfSafe(MediaAsset $media, bool $dryRun = false): array
    {
        $path = $media->getFilePath();
        if (!$this->isClassicImage($media)) {
            return $this->skipped($path, 'média non standard', $dryRun);
        }

        if (!$this->isDeletableMasterPath($path)) {
            return $this->skipped($path, 'chemin maître absent ou non supprimable', $dryRun);
        }

        $validation = $this->validateCriticalVariants($media);
        if (!$validation['valid']) {
            return $this->skipped($path, $validation['reason'], $dryRun);
        }

        $absolutePath = $this->absolutePublicPath((string) $path);
        if ($absolutePath === null || !is_file($absolutePath)) {
            return $this->skipped($path, 'fichier maître déjà absent', $dryRun);
        }

        $bytes = (int) (filesize($absolutePath) ?: 0);
        if ($dryRun) {
            return [
                'deleted' => false,
                'skipped' => false,
                'dryRun' => true,
                'reason' => null,
                'path' => $path,
                'bytes' => $bytes,
            ];
        }

        if (!@unlink($absolutePath)) {
            $this->logger->warning('Impossible de supprimer le fichier maître public.', [
                'mediaId' => $media->getId(),
                'path' => $path,
            ]);

            return $this->skipped($path, 'suppression du fichier impossible', false);
        }

        $metadata = $media->getMetadata() ?? [];
        $metadata['deletedPublicMasterPath'] = $path;
        $metadata['deletedPublicMasterBytes'] = $bytes;
        $metadata['deletedPublicMasterAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $media
            ->setMetadata($metadata)
            ->setFilePath(null);

        return [
            'deleted' => true,
            'skipped' => false,
            'dryRun' => false,
            'reason' => null,
            'path' => $path,
            'bytes' => $bytes,
        ];
    }

    private function publicWebpFileIsReadable(mixed $path): bool
    {
        if (!is_string($path) || $path === '') {
            return false;
        }

        $absolutePath = $this->absolutePublicPath($path);
        if ($absolutePath === null || !is_file($absolutePath) || !is_readable($absolutePath)) {
            return false;
        }

        $imageSize = @getimagesize($absolutePath);

        return is_array($imageSize)
            && $imageSize['mime'] === 'image/webp'
            && (int) $imageSize[0] > 0
            && (int) $imageSize[1] > 0;
    }

    private function isDeletableMasterPath(?string $path): bool
    {
        if (!is_string($path) || $path === '') {
            return false;
        }

        if (
            str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)
        ) {
            return false;
        }

        if (!str_starts_with($path, '/uploads/media/')) {
            return false;
        }

        $relative = substr($path, strlen('/uploads/media/'));

        return $relative !== ''
            && !str_contains($relative, '/')
            && basename($path) !== '.gitkeep';
    }

    private function absolutePublicPath(string $path): ?string
    {
        if (
            str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)
            || !str_starts_with($path, '/uploads/')
        ) {
            return null;
        }

        $publicDirectory = rtrim($this->parameterBag->get('kernel.project_dir'), '/').'/public';
        $absolutePath = $publicDirectory.$path;
        $realPublicUploads = realpath($publicDirectory.'/uploads');
        $realPath = realpath($absolutePath);

        if ($realPublicUploads === false || $realPath === false || !str_starts_with($realPath, $realPublicUploads.'/')) {
            return null;
        }

        return $realPath;
    }

    /** @return array{deleted: false, skipped: true, dryRun: bool, reason: ?string, path: ?string, bytes: 0} */
    private function skipped(?string $path, ?string $reason, bool $dryRun): array
    {
        return [
            'deleted' => false,
            'skipped' => true,
            'dryRun' => $dryRun,
            'reason' => $reason,
            'path' => $path,
            'bytes' => 0,
        ];
    }
}
