<?php

namespace App\Service\Media;

use App\Enum\ImageType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageTypeDetector
{
    private const MIN_360_WIDTH = 3000;
    private const MIN_180_WIDTH = 1600;
    private const MIN_360_RATIO = 1.9;
    private const MAX_360_RATIO = 2.1;
    private const MIN_WIDE_ANGLE_RATIO = 1.65;
    private const MAX_WIDE_ANGLE_RATIO = 2.2;
    private const MIN_PANORAMA_RATIO = 2.2;

    /** @var array<string, true> */
    private const MIME_TYPES_COMPATIBLE_WITH_360 = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
    ];

    /**
     * Conservative image type detection used only when the user did not submit a valid manual choice.
     *
     * Rules:
     * - filenames containing "180" are classified as 180 images;
     * - 360 detection is limited to JPEG, PNG and WebP, never GIF, with a near 2:1 ratio and at least 3000 px width;
     * - very wide images above 2.2:1 are panoramas;
     * - smaller near 2:1 images are treated as 180 half-panoramas;
     * - wide images between 1.65:1 and 2.2:1 are wide angle;
     * - anything ambiguous falls back to standard.
     */
    public function detect(int $width, int $height, ?string $mimeType = null, ?string $originalFilename = null): ImageType
    {
        if ($width < 1 || $height < 1) {
            return ImageType::Standard;
        }

        if ($this->filenameSuggests180($originalFilename)) {
            return ImageType::Degree180;
        }

        $ratio = $width / $height;
        if ($this->isNearEquirectangularRatio($ratio)) {
            if ($width >= self::MIN_360_WIDTH && $this->isMimeTypeCompatibleWith360($mimeType)) {
                return ImageType::Degree360;
            }

            if ($width >= self::MIN_180_WIDTH && $width < self::MIN_360_WIDTH) {
                return ImageType::Degree180;
            }
        }

        if ($ratio > self::MIN_PANORAMA_RATIO) {
            return ImageType::Panorama;
        }

        if ($ratio >= self::MIN_WIDE_ANGLE_RATIO && $ratio <= self::MAX_WIDE_ANGLE_RATIO) {
            return ImageType::WideAngle;
        }

        return ImageType::Standard;
    }

    public function detectFromUpload(UploadedFile $file): ImageType
    {
        $imageSize = @getimagesize($file->getPathname());
        if (!is_array($imageSize) || !isset($imageSize[0], $imageSize[1])) {
            return ImageType::Standard;
        }

        $detectedMimeType = $imageSize['mime'] ?? null;
        $mimeType = is_string($detectedMimeType) && $detectedMimeType !== ''
            ? $detectedMimeType
            : $file->getMimeType();

        return $this->detect(
            (int) $imageSize[0],
            (int) $imageSize[1],
            is_string($mimeType) && $mimeType !== '' ? $mimeType : null,
            $file->getClientOriginalName(),
        );
    }

    private function isNearEquirectangularRatio(float $ratio): bool
    {
        return $ratio >= self::MIN_360_RATIO && $ratio <= self::MAX_360_RATIO;
    }

    private function isMimeTypeCompatibleWith360(?string $mimeType): bool
    {
        return isset(self::MIME_TYPES_COMPATIBLE_WITH_360[strtolower(trim((string) $mimeType))]);
    }

    private function filenameSuggests180(?string $originalFilename): bool
    {
        return str_contains(strtolower((string) $originalFilename), '180');
    }
}
