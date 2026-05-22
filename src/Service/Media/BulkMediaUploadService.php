<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BulkMediaUploadService
{
    public const MAX_FILES_PER_SELECTION = 50;
    public const CLASSIC_MAX_BYTES = 31_457_280; // 30 MiB
    public const PANORAMA_MAX_BYTES = 52_428_800; // 50 MiB

    /** @return array{maxFiles: int, classicMaxBytes: int, panoramaMaxBytes: int} */
    public function clientPolicy(): array
    {
        return [
            'maxFiles' => self::MAX_FILES_PER_SELECTION,
            'classicMaxBytes' => self::CLASSIC_MAX_BYTES,
            'panoramaMaxBytes' => self::PANORAMA_MAX_BYTES,
        ];
    }

    /** @return array<string, mixed> */
    public function successPayload(MediaAsset $media, UploadedFile $file): array
    {
        return [
            'success' => true,
            'mediaId' => $media->getId(),
            'originalDisplayName' => $file->getClientOriginalName(),
            'masterPath' => $this->masterPath($media),
            'displayPath' => $this->displayPath($media),
            'thumbnailPath' => $media->getThumbnailPath(),
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'size' => $media->getFileSize(),
            'detectedMime' => $media->getMimeType(),
            'mediaType' => $media->getImageType()?->value ?? $media->getMediaType()?->value,
        ];
    }

    /** @return array<string, mixed> */
    public function errorPayload(?UploadedFile $file, string $error): array
    {
        return [
            'success' => false,
            'originalDisplayName' => $file?->getClientOriginalName(),
            'error' => $error,
        ];
    }

    private function masterPath(MediaAsset $media): ?string
    {
        $metadata = $media->getMetadata();
        if (is_array($metadata) && isset($metadata['originalPath']) && is_string($metadata['originalPath'])) {
            return $metadata['originalPath'];
        }

        return $media->getFilePath();
    }

    private function displayPath(MediaAsset $media): ?string
    {
        $variants = $media->getVariants();
        if (is_array($variants) && isset($variants['large']) && is_array($variants['large'])) {
            $large = $variants['large'];

            return is_string($large['webp'] ?? null)
                ? $large['webp']
                : (is_string($large['fallback'] ?? null) ? $large['fallback'] : $media->getFilePath());
        }

        return $media->getFilePath();
    }
}
