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
    private const MAX_BYTES = 26_214_400;
    private const MAX_PIXELS = 80_000_000;
    private const MAX_VIEWER_WIDTH = 8192;
    private const MOBILE_VIEWER_WIDTH = 4096;
    private const THUMBNAIL_WIDTH = 1280;
    private const THUMBNAIL_HEIGHT = 720;
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
    public function upload(UploadedFile $file): array
    {
        $inspection = $this->inspect($file);
        $originalTitle = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'panorama-360';
        $safeName = strtolower((string) $this->slugger->slug($originalTitle));
        $uniqueName = sprintf('%s-%s', $safeName, bin2hex(random_bytes(8)));
        $extension = self::ALLOWED_MIME_EXTENSIONS[$inspection['mimeType']];

        $baseDirectory = $this->baseDirectory();
        $originalDirectory = $baseDirectory.'/originals';
        $mobileDirectory = $baseDirectory.'/mobile';
        $thumbnailDirectory = $baseDirectory.'/thumbs';
        $this->ensureDirectory($baseDirectory);
        $this->ensureDirectory($originalDirectory);
        $this->ensureDirectory($mobileDirectory);
        $this->ensureDirectory($thumbnailDirectory);

        $originalFilename = sprintf('%s-original.%s', $uniqueName, $extension);
        $viewerFilename = sprintf('%s.%s', $uniqueName, $extension);
        $mobileFilename = sprintf('%s-mobile.%s', $uniqueName, $extension);
        $thumbnailFilename = sprintf('%s-thumb.%s', $uniqueName, $extension);

        $file->move($originalDirectory, $originalFilename);
        $originalFile = $originalDirectory.'/'.$originalFilename;
        $viewerFile = $baseDirectory.'/'.$viewerFilename;
        $mobileFile = $mobileDirectory.'/'.$mobileFilename;
        $thumbnailFile = $thumbnailDirectory.'/'.$thumbnailFilename;

        $viewerDimensions = $this->createViewerImage(
            $originalFile,
            $viewerFile,
            $inspection['mimeType'],
            $inspection['width'],
            $inspection['height'],
            self::MAX_VIEWER_WIDTH,
        );
        $mobileDimensions = null;
        if ($inspection['width'] > self::MOBILE_VIEWER_WIDTH) {
            $mobileDimensions = $this->createViewerImage(
                $originalFile,
                $mobileFile,
                $inspection['mimeType'],
                $inspection['width'],
                $inspection['height'],
                self::MOBILE_VIEWER_WIDTH,
            );
        }
        $thumbnailDimensions = $this->createThumbnail(
            $originalFile,
            $thumbnailFile,
            $inspection['mimeType'],
            $inspection['width'],
            $inspection['height'],
        );

        return [
            'title' => $originalTitle,
            'path' => self::PUBLIC_DIRECTORY.'/'.$viewerFilename,
            'thumbnailPath' => self::PUBLIC_DIRECTORY.'/thumbs/'.$thumbnailFilename,
            'mimeType' => $inspection['mimeType'],
            'fileSize' => (int) (filesize($viewerFile) ?: $inspection['fileSize']),
            'width' => $viewerDimensions['width'],
            'height' => $viewerDimensions['height'],
            'projection' => 'equirectangular',
            'metadata' => [
                'originalPath' => self::PUBLIC_DIRECTORY.'/originals/'.$originalFilename,
                'originalWidth' => $inspection['width'],
                'originalHeight' => $inspection['height'],
                'originalFileSize' => $inspection['fileSize'],
                'viewerWidth' => $viewerDimensions['width'],
                'viewerHeight' => $viewerDimensions['height'],
                'mobilePath' => $mobileDimensions !== null ? self::PUBLIC_DIRECTORY.'/mobile/'.$mobileFilename : null,
                'mobileWidth' => $mobileDimensions['width'] ?? null,
                'mobileHeight' => $mobileDimensions['height'] ?? null,
                'thumbnailWidth' => $thumbnailDimensions['width'],
                'thumbnailHeight' => $thumbnailDimensions['height'],
                'ratio' => round($inspection['width'] / $inspection['height'], 4),
                'quality' => 'high',
                'source' => 'drone_panorama_upload',
            ],
        ];
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
        if ($fileSize === null || $fileSize <= 0) {
            throw new InvalidArgumentException('le fichier est vide.');
        }

        if ($fileSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('la taille maximale autorisée pour une image 360° est 25 Mo.');
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
        if (!is_array($imageSize) || !isset($imageSize[0], $imageSize[1])) {
            throw new InvalidArgumentException('le contenu du fichier n’est pas une image lisible.');
        }

        $detectedMime = (string) ($imageSize['mime'] ?? '');
        if ($detectedMime === '' || $detectedMime !== $mimeType || !isset(self::ALLOWED_MIME_EXTENSIONS[$detectedMime])) {
            throw new InvalidArgumentException('le type réel de l’image ne correspond pas au fichier envoyé.');
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('les dimensions de l’image sont invalides ou trop grandes.');
        }

        $ratio = $width / $height;
        if ($ratio < self::MIN_EQUIRECTANGULAR_RATIO || $ratio > self::MAX_EQUIRECTANGULAR_RATIO) {
            throw new InvalidArgumentException('cette image ne semble pas avoir un ratio 360° équirectangulaire. Une image 360° devrait généralement avoir une largeur environ deux fois plus grande que sa hauteur.');
        }

        return [
            'mimeType' => $mimeType,
            'fileSize' => $fileSize,
            'width' => $width,
            'height' => $height,
        ];
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
        $targetWidth = self::THUMBNAIL_WIDTH;
        $targetHeight = self::THUMBNAIL_HEIGHT;
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int) round(($sourceHeight - $cropHeight) / 2);
        }

        $sourceImage = $this->createImage($sourceFile, $mimeType);
        $targetImage = $this->createCanvas($targetWidth, $targetHeight, $mimeType);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            $cropX,
            $cropY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight,
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

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('le dossier de stockage des images 360° ne peut pas être créé.');
        }
    }
}
