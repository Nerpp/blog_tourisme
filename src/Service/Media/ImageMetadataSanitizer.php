<?php

namespace App\Service\Media;

use GdImage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ImageMetadataSanitizer
{
    private const JPEG_QUALITY = 92;
    private const WEBP_QUALITY = 90;
    private const PNG_COMPRESSION = 5;

    /** @var array<string, string> */
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function supportsMimeType(?string $mimeType): bool
    {
        return $mimeType !== null && isset(self::SUPPORTED_MIME_TYPES[$mimeType]);
    }

    /**
     * @return array{
     *     path: string,
     *     absolutePath: string,
     *     mimeType: string,
     *     width: int,
     *     height: int,
     *     markers: list<string>,
     *     hasSensitiveMetadata: bool,
     *     supported: bool
     * }
     */
    public function inspectPublicPath(string $publicPath): array
    {
        $absolutePath = $this->resolvePublicUploadPath($publicPath);
        $imageSize = @getimagesize($absolutePath);
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException(sprintf('Le fichier "%s" n’est pas une image lisible.', $publicPath));
        }

        $mimeType = (string) $imageSize['mime'];
        $markers = $this->detectSensitiveMetadata($absolutePath, $mimeType, $imageSize);

        return [
            'path' => $publicPath,
            'absolutePath' => $absolutePath,
            'mimeType' => $mimeType,
            'width' => (int) $imageSize[0],
            'height' => (int) $imageSize[1],
            'markers' => $markers,
            'hasSensitiveMetadata' => $markers !== [],
            'supported' => $this->supportsMimeType($mimeType),
        ];
    }

    /**
     * @return array{
     *     path: string,
     *     absolutePath: string,
     *     mimeType: string,
     *     width: int,
     *     height: int,
     *     previousWidth: int,
     *     previousHeight: int,
     *     markersBefore: list<string>,
     *     markersAfter: list<string>
     * }
     */
    public function sanitizePublicPath(string $publicPath, bool $applyOrientation = true): array
    {
        $before = $this->inspectPublicPath($publicPath);
        if (!$before['supported']) {
            throw new InvalidArgumentException(sprintf('Le type "%s" ne peut pas être nettoyé sans dépendance externe.', $before['mimeType']));
        }

        $sourceImage = $this->createImage($before['absolutePath'], $before['mimeType']);
        if ($before['mimeType'] === 'image/jpeg' && $applyOrientation) {
            $sourceImage = $this->applyJpegOrientation($sourceImage, $before['absolutePath']);
        }

        $tmpFile = $before['absolutePath'].'.sanitized.'.bin2hex(random_bytes(6));
        try {
            $this->saveImage($sourceImage, $tmpFile, $before['mimeType']);
            if (!rename($tmpFile, $before['absolutePath'])) {
                throw new RuntimeException(sprintf('Le remplacement de "%s" a échoué.', $publicPath));
            }
        } finally {
            imagedestroy($sourceImage);
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        $after = $this->inspectPublicPath($publicPath);

        return [
            'path' => $publicPath,
            'absolutePath' => $after['absolutePath'],
            'mimeType' => $after['mimeType'],
            'width' => $after['width'],
            'height' => $after['height'],
            'previousWidth' => $before['width'],
            'previousHeight' => $before['height'],
            'markersBefore' => $before['markers'],
            'markersAfter' => $after['markers'],
        ];
    }

    public function publicPathForAbsolutePath(string $absolutePath): string
    {
        $publicDirectory = $this->publicDirectory();
        $realPublicDirectory = realpath($publicDirectory);
        $realPath = realpath($absolutePath);

        if ($realPublicDirectory === false || $realPath === false || !str_starts_with($realPath, $realPublicDirectory.'/uploads/')) {
            throw new InvalidArgumentException('Le fichier doit être dans public/uploads.');
        }

        return '/'.ltrim(substr($realPath, strlen($realPublicDirectory)), '/');
    }

    private function resolvePublicUploadPath(string $publicPath): string
    {
        if (str_starts_with($publicPath, 'http://') || str_starts_with($publicPath, 'https://')) {
            throw new InvalidArgumentException('Les URLs externes ne peuvent pas être nettoyées localement.');
        }

        $absolutePath = $this->publicDirectory().'/'.ltrim($publicPath, '/');
        $realPublicDirectory = realpath($this->publicDirectory());
        $realPath = realpath($absolutePath);

        if ($realPublicDirectory === false || $realPath === false || !str_starts_with($realPath, $realPublicDirectory.'/uploads/')) {
            throw new InvalidArgumentException(sprintf('Le fichier "%s" est introuvable ou hors de public/uploads.', $publicPath));
        }

        return $realPath;
    }

    /**
     * @param array<int|string, mixed> $imageSize
     *
     * @return list<string>
     */
    private function detectSensitiveMetadata(string $absolutePath, string $mimeType, array $imageSize): array
    {
        $markers = [];
        $contents = (string) file_get_contents($absolutePath);

        if ($mimeType === 'image/jpeg') {
            if (str_contains($contents, "Exif\0\0")) {
                $markers[] = 'EXIF';
            }

            $app13 = $imageSize['APP13'] ?? null;
            if (is_string($app13) && function_exists('iptcparse')) {
                $iptc = @iptcparse($app13);
                if (is_array($iptc) && $iptc !== []) {
                    $markers[] = 'IPTC';
                }
            } elseif ($app13 !== null) {
                $markers[] = 'IPTC';
            }

            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($absolutePath, null, true, false);
                if (is_array($exif)) {
                    if (isset($exif['GPS']) && is_array($exif['GPS']) && $exif['GPS'] !== []) {
                        $markers[] = 'GPS';
                    }

                    if ($this->exifHasCameraOrSoftwareData($exif)) {
                        $markers[] = 'APPAREIL/LOGICIEL';
                    }
                }
            }
        }

        if (str_contains($contents, '<x:xmpmeta') || str_contains($contents, 'http://ns.adobe.com/xap/1.0/')) {
            $markers[] = 'XMP';
        }

        if ($mimeType === 'image/png') {
            foreach (['eXIf' => 'EXIF', 'iTXt' => 'PNG_TEXT', 'tEXt' => 'PNG_TEXT', 'zTXt' => 'PNG_TEXT'] as $chunk => $marker) {
                if (str_contains($contents, $chunk)) {
                    $markers[] = $marker;
                }
            }
        }

        if ($mimeType === 'image/webp') {
            foreach (['EXIF' => 'EXIF', 'XMP ' => 'XMP', 'ICCP' => 'ICC_PROFILE'] as $chunk => $marker) {
                if (str_contains($contents, $chunk)) {
                    $markers[] = $marker;
                }
            }
        }

        return array_values(array_unique($markers));
    }

    /** @param array<array-key, mixed> $exif */
    private function exifHasCameraOrSoftwareData(array $exif): bool
    {
        foreach (['IFD0', 'EXIF'] as $section) {
            if (!isset($exif[$section]) || !is_array($exif[$section])) {
                continue;
            }

            foreach (['Make', 'Model', 'Software', 'DateTime', 'DateTimeOriginal', 'DateTimeDigitized', 'BodySerialNumber', 'CameraOwnerName'] as $key) {
                $value = $exif[$section][$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function createImage(string $path, string $mimeType): GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        };

        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('L’image ne peut pas être ouverte par GD.');
        }

        return $image;
    }

    private function applyJpegOrientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = $this->jpegOrientation($exif);

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip($this->rotate($image, 270), IMG_FLIP_HORIZONTAL),
            6 => $this->rotate($image, 270),
            7 => $this->flip($this->rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    private function jpegOrientation(mixed $exif): int
    {
        if (!is_array($exif)) {
            return 1;
        }

        $ifd0 = $exif['IFD0'] ?? null;
        $orientation = is_array($ifd0) ? ($ifd0['Orientation'] ?? null) : null;
        $orientation ??= $exif['Orientation'] ?? null;

        if (is_int($orientation)) {
            return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        }

        if (!is_string($orientation) || preg_match('/^[1-8]$/D', $orientation) !== 1) {
            return 1;
        }

        return (int) $orientation;
    }

    private function rotate(GdImage $image, int $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);
        if (!$rotated instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function saveImage(GdImage $image, string $targetFile, string $mimeType): void
    {
        $saved = match ($mimeType) {
            'image/jpeg' => $this->saveJpeg($image, $targetFile),
            'image/png' => imagepng($image, $targetFile, self::PNG_COMPRESSION),
            'image/webp' => imagewebp($image, $targetFile, self::WEBP_QUALITY),
            default => false,
        };

        if (!$saved) {
            throw new RuntimeException('L’enregistrement de l’image nettoyée a échoué.');
        }
    }

    private function saveJpeg(GdImage $image, string $targetFile): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, $targetFile, self::JPEG_QUALITY);
    }

    private function publicDirectory(): string
    {
        return rtrim($this->parameterBag->get('kernel.project_dir'), '/').'/public';
    }
}
