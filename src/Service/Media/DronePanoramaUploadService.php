<?php

namespace App\Service\Media;

use GdImage;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class DronePanoramaUploadService
{
    private const PUBLIC_DIRECTORY = '/uploads/media/360';
    private const STAGING_DIRECTORY = '.staging';
    private const MAX_BYTES = BulkMediaUploadService::PANORAMA_MAX_BYTES;
    private const MAX_PIXELS = 80_000_000;
    private const MAX_VIEWER_WIDTH = 8192;
    private const MOBILE_VIEWER_WIDTH = 4096;
    private const THUMBNAIL_WIDTH = 1280;
    private const MIN_EQUIRECTANGULAR_RATIO = 1.9;
    private const MAX_EQUIRECTANGULAR_RATIO = 2.1;

    /** @var array<string, string> */
    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var array<string, list<string>> */
    private const MIME_ALLOWED_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly SluggerInterface $slugger,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
    ) {
    }

    /**
     * @return array{
     *     title: string,
     *     path: string,
     *     thumbnailPath: string,
     *     mimeType: string,
     *     fileSize: int,
     *     width: int,
     *     height: int,
     *     projection: string,
     *     metadata: array<string, mixed>
     * }
     */
    public function upload(UploadedFile $file, ?string $basenameSeed = null): array
    {
        $inspection = $this->inspect($file);
        $originalTitle = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'panorama-360';
        $safeName = $basenameSeed !== null && $basenameSeed !== ''
            ? strtolower((string) $this->slugger->slug($basenameSeed))
            : strtolower((string) $this->slugger->slug($originalTitle));
        $uniqueName = sprintf('%s-%s', $safeName, bin2hex(random_bytes(8)));
        $extension = self::ALLOWED_MIME_EXTENSIONS[$inspection['mimeType']];

        $baseDirectory = $this->baseDirectory();
        $originalDirectory = $baseDirectory.'/originals';
        $mobileDirectory = $baseDirectory.'/mobile';
        $thumbnailDirectory = $baseDirectory.'/thumbs';
        $originalFilename = sprintf('%s-original.%s', $uniqueName, $extension);
        $viewerFilename = sprintf('%s.%s', $uniqueName, $extension);
        $mobileFilename = sprintf('%s-mobile.%s', $uniqueName, $extension);
        $thumbnailFilename = sprintf('%s-thumb.%s', $uniqueName, $extension);

        $stagingDirectory = $baseDirectory.'/'.self::STAGING_DIRECTORY;
        $attemptDirectory = null;
        $promotedFiles = [];

        try {
            $this->ensureDirectory($baseDirectory);
            $this->ensureDirectory($stagingDirectory);
            $this->assertExistingPathInsideDirectory($stagingDirectory, $baseDirectory);
            $attemptDirectory = $this->createAttemptDirectory($stagingDirectory, $uniqueName);

            $stagedOriginalFile = $attemptDirectory.'/'.$originalFilename;
            $stagedViewerFile = $attemptDirectory.'/'.$viewerFilename;
            $stagedMobileFile = $attemptDirectory.'/'.$mobileFilename;
            $stagedThumbnailFile = $attemptDirectory.'/'.$thumbnailFilename;

            if (!copy($file->getPathname(), $stagedOriginalFile)) {
                throw new InvalidArgumentException('la copie temporaire de l’image 360° a échoué.');
            }

            $sanitizedOriginal = $this->imageMetadataSanitizer->sanitizePublicPath(
                self::PUBLIC_DIRECTORY.'/'.self::STAGING_DIRECTORY.'/'.basename($attemptDirectory).'/'.$originalFilename,
                applyOrientation: false,
            );
            $sourceWidth = $sanitizedOriginal['width'];
            $sourceHeight = $sanitizedOriginal['height'];
            $sourceMimeType = $sanitizedOriginal['mimeType'];
            $this->assertEquirectangularDimensions($sourceWidth, $sourceHeight);

            $viewerDimensions = $this->createViewerImage(
                $stagedOriginalFile,
                $stagedViewerFile,
                $sourceMimeType,
                $sourceWidth,
                $sourceHeight,
                self::MAX_VIEWER_WIDTH,
            );
            $mobileDimensions = null;
            if ($sourceWidth > self::MOBILE_VIEWER_WIDTH) {
                $mobileDimensions = $this->createViewerImage(
                    $stagedOriginalFile,
                    $stagedMobileFile,
                    $sourceMimeType,
                    $sourceWidth,
                    $sourceHeight,
                    self::MOBILE_VIEWER_WIDTH,
                );
            }
            $thumbnailDimensions = $this->createThumbnail(
                $stagedOriginalFile,
                $stagedThumbnailFile,
                $sourceMimeType,
                $sourceWidth,
                $sourceHeight,
            );

            $viewerFileSize = (int) (filesize($stagedViewerFile) ?: $inspection['fileSize']);
            $originalFileSize = (int) (filesize($stagedOriginalFile) ?: $inspection['fileSize']);
            $filesToPromote = [
                $stagedOriginalFile => $originalDirectory.'/'.$originalFilename,
                $stagedViewerFile => $baseDirectory.'/'.$viewerFilename,
            ];
            if ($mobileDimensions !== null) {
                $filesToPromote[$stagedMobileFile] = $mobileDirectory.'/'.$mobileFilename;
            }
            $filesToPromote[$stagedThumbnailFile] = $thumbnailDirectory.'/'.$thumbnailFilename;

            foreach ($filesToPromote as $stagedFile => $finalFile) {
                $this->ensureDirectory(dirname($finalFile));
                $this->promoteFile($stagedFile, $finalFile, $baseDirectory);
                $promotedFiles[] = $finalFile;
            }

            $this->removeAttemptDirectory($attemptDirectory, $stagingDirectory);

            return [
                'title' => $originalTitle,
                'path' => self::PUBLIC_DIRECTORY.'/'.$viewerFilename,
                'thumbnailPath' => self::PUBLIC_DIRECTORY.'/thumbs/'.$thumbnailFilename,
                'mimeType' => $sourceMimeType,
                'fileSize' => $viewerFileSize,
                'width' => $viewerDimensions['width'],
                'height' => $viewerDimensions['height'],
                'projection' => 'equirectangular',
                'metadata' => [
                    'originalPath' => self::PUBLIC_DIRECTORY.'/originals/'.$originalFilename,
                    'originalWidth' => $sourceWidth,
                    'originalHeight' => $sourceHeight,
                    'originalFileSize' => $originalFileSize,
                    'viewerWidth' => $viewerDimensions['width'],
                    'viewerHeight' => $viewerDimensions['height'],
                    'mobilePath' => $mobileDimensions !== null ? self::PUBLIC_DIRECTORY.'/mobile/'.$mobileFilename : null,
                    'mobileWidth' => $mobileDimensions['width'] ?? null,
                    'mobileHeight' => $mobileDimensions['height'] ?? null,
                    'thumbnailWidth' => $thumbnailDimensions['width'],
                    'thumbnailHeight' => $thumbnailDimensions['height'],
                    'ratio' => round($sourceWidth / $sourceHeight, 4),
                    'quality' => 'high',
                    'source' => 'drone_panorama_upload',
                    'metadataSanitized' => true,
                ],
            ];
        } catch (\Throwable $exception) {
            $cleanupFailed = false;
            try {
                $this->rollbackPromotedFiles($promotedFiles, $baseDirectory);
            } catch (\Throwable) {
                $cleanupFailed = true;
            }

            if ($attemptDirectory !== null) {
                try {
                    $this->removeAttemptDirectory($attemptDirectory, $stagingDirectory);
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }

            if ($cleanupFailed) {
                throw new InvalidArgumentException(
                    'le nettoyage des fichiers incomplets de l’image 360° a échoué.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * @return array{mimeType: string, fileSize: int, width: int, height: int}
     */
    private function inspect(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('le transfert est incomplet ou refusé par PHP.');
        }

        $fileSize = $file->getSize();
        if ($fileSize === false || $fileSize <= 0) {
            throw new InvalidArgumentException('le fichier est vide.');
        }

        if ($fileSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('la taille maximale autorisée pour une image 360° est 50 Mo.');
        }

        $clientExtension = strtolower($file->getClientOriginalExtension());
        if (!in_array($clientExtension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new InvalidArgumentException('seuls les fichiers JPG, PNG et WebP sont acceptés pour une image 360°.');
        }

        $mimeType = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIME_EXTENSIONS[$mimeType])) {
            throw new InvalidArgumentException('seuls les fichiers JPEG, PNG et WebP sont acceptés pour une image 360°.');
        }

        if (!in_array($clientExtension, self::MIME_ALLOWED_EXTENSIONS[$mimeType], true)) {
            throw new InvalidArgumentException('l’extension du fichier ne correspond pas au type réel de l’image.');
        }

        $imageSize = @getimagesize($file->getPathname());
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException('le contenu du fichier n’est pas une image lisible.');
        }

        $detectedMime = (string) $imageSize['mime'];
        if ($detectedMime === '' || $detectedMime !== $mimeType || !array_key_exists($detectedMime, self::ALLOWED_MIME_EXTENSIONS)) {
            throw new InvalidArgumentException('le type réel de l’image ne correspond pas au fichier envoyé.');
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('les dimensions de l’image sont invalides ou trop grandes.');
        }

        $ratio = $width / $height;
        $this->assertEquirectangularRatio($ratio);

        return [
            'mimeType' => $mimeType,
            'fileSize' => $fileSize,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function assertEquirectangularDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('les dimensions de l’image 360° nettoyée sont invalides ou trop grandes.');
        }

        $this->assertEquirectangularRatio($width / $height);
    }

    private function assertEquirectangularRatio(float $ratio): void
    {
        if ($ratio < self::MIN_EQUIRECTANGULAR_RATIO || $ratio > self::MAX_EQUIRECTANGULAR_RATIO) {
            throw new InvalidArgumentException('cette image ne semble pas avoir un ratio 360° équirectangulaire. Une image 360° devrait généralement avoir une largeur environ deux fois plus grande que sa hauteur.');
        }
    }

    /**
     * @return array{width: int, height: int}
     */
    private function createViewerImage(string $sourceFile, string $targetFile, string $mimeType, int $sourceWidth, int $sourceHeight, int $maxWidth): array
    {
        if ($sourceWidth <= $maxWidth) {
            if (!copy($sourceFile, $targetFile)) {
                throw new InvalidArgumentException('la copie de l’image 360° a échoué.');
            }

            return ['width' => $sourceWidth, 'height' => $sourceHeight];
        }

        $targetWidth = $maxWidth;
        $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));
        $sourceImage = $this->createImage($sourceFile, $mimeType);
        $targetImage = $this->createCanvas($targetWidth, $targetHeight, $mimeType);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        $this->saveImage($targetImage, $targetFile, $mimeType);
        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        return ['width' => $targetWidth, 'height' => $targetHeight];
    }

    /**
     * @return array{width: int, height: int}
     */
    private function createThumbnail(string $sourceFile, string $targetFile, string $mimeType, int $sourceWidth, int $sourceHeight): array
    {
        $targetWidth = min($sourceWidth, self::THUMBNAIL_WIDTH);
        $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));

        $sourceImage = $this->createImage($sourceFile, $mimeType);
        $targetImage = $this->createCanvas($targetWidth, $targetHeight, $mimeType);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        $this->saveImage($targetImage, $targetFile, $mimeType);
        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        return ['width' => $targetWidth, 'height' => $targetHeight];
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
            throw new InvalidArgumentException('l’image 360° ne peut pas être traitée.');
        }

        return $image;
    }

    private function createCanvas(int $width, int $height, string $mimeType): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('la génération de l’image 360° a échoué.');
        }

        if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefilledrectangle($image, 0, 0, $width, $height, $transparent);
        }

        return $image;
    }

    private function saveImage(GdImage $image, string $targetFile, string $mimeType): void
    {
        $saved = match ($mimeType) {
            'image/jpeg' => $this->saveJpeg($image, $targetFile),
            'image/png' => imagepng($image, $targetFile, 4),
            'image/webp' => imagewebp($image, $targetFile, 90),
            default => false,
        };

        if (!$saved) {
            throw new InvalidArgumentException('l’enregistrement de l’image 360° a échoué.');
        }
    }

    private function saveJpeg(GdImage $image, string $targetFile): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, $targetFile, 92);
    }

    private function baseDirectory(): string
    {
        return $this->parameterBag->get('kernel.project_dir').'/public'.self::PUBLIC_DIRECTORY;
    }

    private function createAttemptDirectory(string $stagingDirectory, string $uniqueName): string
    {
        $attemptDirectory = $stagingDirectory.'/'.$uniqueName.'-'.bin2hex(random_bytes(6));
        if (file_exists($attemptDirectory) || is_link($attemptDirectory) || !mkdir($attemptDirectory, 0775)) {
            throw new InvalidArgumentException('le dossier temporaire de l’image 360° ne peut pas être créé.');
        }

        $this->assertExistingPathInsideDirectory($attemptDirectory, $stagingDirectory);

        return $attemptDirectory;
    }

    private function promoteFile(string $sourceFile, string $targetFile, string $baseDirectory): void
    {
        $this->assertExistingPathInsideDirectory($sourceFile, $baseDirectory);
        $this->assertExistingPathInsideDirectory(dirname($targetFile), $baseDirectory);

        if (file_exists($targetFile) || is_link($targetFile)) {
            throw new InvalidArgumentException('un fichier panorama portant ce nom existe déjà.');
        }

        if (!link($sourceFile, $targetFile)) {
            throw new InvalidArgumentException('la mise à disposition de l’image 360° a échoué.');
        }
    }

    /** @param list<string> $promotedFiles */
    private function rollbackPromotedFiles(array $promotedFiles, string $baseDirectory): void
    {
        foreach (array_reverse($promotedFiles) as $promotedFile) {
            if (!file_exists($promotedFile) && !is_link($promotedFile)) {
                continue;
            }

            $this->assertExistingPathInsideDirectory($promotedFile, $baseDirectory);
            if (!unlink($promotedFile)) {
                throw new InvalidArgumentException('le nettoyage d’un fichier panorama incomplet a échoué.');
            }
        }
    }

    private function removeAttemptDirectory(string $attemptDirectory, string $stagingDirectory): void
    {
        if (!file_exists($attemptDirectory) && !is_link($attemptDirectory)) {
            return;
        }

        $this->assertExistingPathInsideDirectory($attemptDirectory, $stagingDirectory);
        $entries = scandir($attemptDirectory);
        if ($entries === false) {
            throw new InvalidArgumentException('le dossier temporaire de l’image 360° ne peut pas être lu.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $attemptDirectory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                throw new InvalidArgumentException('le dossier temporaire de l’image 360° contient un élément inattendu.');
            }

            if (!unlink($path)) {
                throw new InvalidArgumentException('le nettoyage d’un fichier temporaire de l’image 360° a échoué.');
            }
        }

        if (!rmdir($attemptDirectory)) {
            throw new InvalidArgumentException('le nettoyage du dossier temporaire de l’image 360° a échoué.');
        }
    }

    private function assertExistingPathInsideDirectory(string $path, string $directory): void
    {
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if (
            $realDirectory === false
            || $realPath === false
            || ($realPath !== $realDirectory && !str_starts_with($realPath, $realDirectory.'/'))
        ) {
            throw new InvalidArgumentException('un chemin de stockage de l’image 360° est invalide.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory) || is_link($directory) || (!mkdir($directory, 0775, true) && !is_dir($directory))) {
            throw new InvalidArgumentException('le dossier de stockage des images 360° ne peut pas être créé.');
        }
    }
}
