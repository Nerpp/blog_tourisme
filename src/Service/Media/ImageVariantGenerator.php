<?php

namespace App\Service\Media;

use DateTimeImmutable;
use GdImage;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ImageVariantGenerator
{
    private const PUBLIC_SOURCE_DIRECTORY = '/uploads/media';
    private const PUBLIC_VARIANT_DIRECTORY = '/uploads/media/variants';
    private const JPEG_QUALITY = 92;
    private const WEBP_QUALITY = 90;
    private const AVIF_QUALITY = 66;
    private const ARTICLE_INLINE_WEBP_QUALITY = 78;
    private const ARTICLE_DISPLAY_WEBP_QUALITY = 79;
    private const ARTICLE_COVER_WEBP_QUALITY = 80;
    private const ARTICLE_SOURCE_WEBP_QUALITY = 82;

    /** @var array<string, int> */
    private const LEGACY_SIZES = [
        'thumb' => 600,
        'medium' => 1920,
        'large' => 2560,
    ];

    /** @var array<string, int> */
    private const STANDARD_SIZES = [
        'thumb' => 600,
        'mobile' => 960,
        'medium' => 1600,
        'large' => 1920,
    ];

    /** @var array<string, string> */
    private const FALLBACK_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function supportsWebp(): bool
    {
        return function_exists('gd_info')
            && function_exists('imagewebp')
            && ((gd_info()['WebP Support'] ?? false) === true);
    }

    public function supportsAvif(): bool
    {
        return function_exists('gd_info')
            && function_exists('imageavif')
            && ((gd_info()['AVIF Support'] ?? false) === true);
    }

    /** @return list<string> */
    public function supportedOutputFormats(): array
    {
        $formats = ['fallback'];

        if ($this->supportsWebp()) {
            $formats[] = 'webp';
        }

        if ($this->supportsAvif()) {
            $formats[] = 'avif';
        }

        return $formats;
    }

    public function supportsMimeType(?string $mimeType): bool
    {
        return $mimeType !== null && isset(self::FALLBACK_EXTENSIONS[$mimeType]);
    }

    /** @return list<string> */
    public function standardOutputFormats(): array
    {
        return ['webp'];
    }

    /**
     * Generate the three WebP resources used only by newly uploaded Article images.
     * The original upload is deliberately not part of the returned data.
     *
     * @return array{
     *     source: array{path: string, mimeType: 'image/webp', width: int, height: int, fileSize: int, generatedAt: string, formats: list<'webp'>},
     *     thumb: array{webp: string, width: int, height: int, fileSize: int},
     *     mobile: array{webp: string, width: int, height: int, fileSize: int},
     *     medium: array{webp: string, width: int, height: int, fileSize: int},
     *     large: array{webp: string, width: int, height: int, fileSize: int}
     * }
     */
    public function generateArticleResponsiveWebps(
        string $publicSourcePath,
        string $basenameSeed,
        int $inlineMaxLongSide = 640,
        int $displayMaxLongSide = 960,
        int $coverMaxLongSide = 1280,
        int $sourceMaxLongSide = 1600,
        string $publicTargetDirectory = self::PUBLIC_SOURCE_DIRECTORY,
    ): array {
        if (!$this->supportsWebp()) {
            throw new InvalidArgumentException('Le support WebP est requis pour optimiser les images d’article.');
        }

        if (
            $inlineMaxLongSide < 1
            || $displayMaxLongSide < $inlineMaxLongSide
            || $coverMaxLongSide < $displayMaxLongSide
            || $sourceMaxLongSide < $coverMaxLongSide
        ) {
            throw new InvalidArgumentException('Les tailles de variantes Article sont invalides.');
        }

        $sourceFile = $this->resolvePublicFile($publicSourcePath);
        $imageSize = @getimagesize($sourceFile);
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException('L’image source est illisible.');
        }

        $mimeType = (string) $imageSize['mime'];
        if (!$this->supportsMimeType($mimeType)) {
            throw new InvalidArgumentException(sprintf('Le type "%s" ne peut pas être traité par le pipeline média.', $mimeType ?: 'inconnu'));
        }

        $sourceWidth = (int) $imageSize[0];
        $sourceHeight = (int) $imageSize[1];
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new InvalidArgumentException('Les dimensions de l’image source sont invalides.');
        }

        $targetDirectory = $this->publicDirectory($publicTargetDirectory);
        $this->ensureDirectory($targetDirectory);

        $contentHash = hash_file('sha256', $sourceFile);
        if (!is_string($contentHash)) {
            throw new InvalidArgumentException('L’image source ne peut pas être identifiée.');
        }

        $baseName = 'article_'.substr(hash(
            'sha256',
            $basenameSeed.'|'.filesize($sourceFile).'|'.$contentHash.'|'.$inlineMaxLongSide.'|'.$displayMaxLongSide.'|'.$coverMaxLongSide.'|'.$sourceMaxLongSide,
        ), 0, 24);
        $sourceImage = $this->createImage($sourceFile, $mimeType);
        $generatedFiles = [];
        /** @var array<string, array{webp: string, width: int, height: int, fileSize: int}> $variantsByDimensions */
        $variantsByDimensions = [];
        $articleVariants = [];

        try {
            foreach ([
                'source' => [$sourceMaxLongSide, self::ARTICLE_SOURCE_WEBP_QUALITY],
                'cover' => [$coverMaxLongSide, self::ARTICLE_COVER_WEBP_QUALITY],
                'display' => [$displayMaxLongSide, self::ARTICLE_DISPLAY_WEBP_QUALITY],
                'inline' => [$inlineMaxLongSide, self::ARTICLE_INLINE_WEBP_QUALITY],
            ] as $role => [$maxLongSide, $quality]) {
                $longSide = max($sourceWidth, $sourceHeight);
                $scale = min(1.0, $maxLongSide / $longSide);
                $targetWidth = max(1, (int) round($sourceWidth * $scale));
                $targetHeight = max(1, (int) round($sourceHeight * $scale));
                $dimensionKey = $targetWidth.'x'.$targetHeight;

                if (isset($variantsByDimensions[$dimensionKey])) {
                    $articleVariants[$role] = $variantsByDimensions[$dimensionKey];

                    continue;
                }

                $variant = $this->generateArticleWebpSize(
                    $sourceImage,
                    $mimeType,
                    $sourceWidth,
                    $sourceHeight,
                    $targetDirectory,
                    $publicTargetDirectory,
                    $baseName,
                    $role,
                    $targetWidth,
                    $targetHeight,
                    $quality,
                );
                $generatedFiles[] = $targetDirectory.'/'.basename($variant['webp']);
                $variantsByDimensions[$dimensionKey] = $variant;
                $articleVariants[$role] = $variant;
            }
        } catch (\Throwable $exception) {
            foreach ($generatedFiles as $generatedFile) {
                if (is_file($generatedFile)) {
                    @unlink($generatedFile);
                }
            }

            throw $exception;
        } finally {
            imagedestroy($sourceImage);
        }

        if (!isset($articleVariants['source'], $articleVariants['inline'], $articleVariants['display'], $articleVariants['cover'])) {
            throw new InvalidArgumentException('Les variantes WebP Article sont incomplètes.');
        }

        $source = $articleVariants['source'];
        $inline = $articleVariants['inline'];
        $display = $articleVariants['display'];
        $cover = $articleVariants['cover'];

        return [
            'source' => [
                'path' => $source['webp'],
                'mimeType' => 'image/webp',
                'width' => $source['width'],
                'height' => $source['height'],
                'fileSize' => $source['fileSize'],
                'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
                'formats' => ['webp'],
            ],
            'thumb' => $inline,
            'mobile' => $display,
            'medium' => $cover,
            'large' => $source,
        ];
    }

    /** @return array{webp: string, width: int, height: int, fileSize: int} */
    private function generateArticleWebpSize(
        GdImage $sourceImage,
        string $mimeType,
        int $sourceWidth,
        int $sourceHeight,
        string $targetDirectory,
        string $publicTargetDirectory,
        string $baseName,
        string $role,
        int $targetWidth,
        int $targetHeight,
        int $quality,
    ): array {
        $targetImage = $this->createCanvas($targetWidth, $targetHeight, $mimeType);
        $filename = sprintf('%s_%s.webp', $baseName, $role);
        $targetFile = $targetDirectory.'/'.$filename;
        $temporaryFile = $targetFile.'.tmp-'.bin2hex(random_bytes(6));

        try {
            if (!imagecopyresampled(
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
            )) {
                throw new InvalidArgumentException('La génération WebP Article a échoué.');
            }

            if (!imagewebp($targetImage, $temporaryFile, $quality)) {
                throw new InvalidArgumentException('La génération WebP Article a échoué.');
            }

            $generatedImageSize = @getimagesize($temporaryFile);
            if (
                !is_array($generatedImageSize)
                || $generatedImageSize['mime'] !== 'image/webp'
                || (int) $generatedImageSize[0] !== $targetWidth
                || (int) $generatedImageSize[1] !== $targetHeight
            ) {
                throw new InvalidArgumentException('Le WebP Article généré est illisible ou incomplet.');
            }

            if (!rename($temporaryFile, $targetFile)) {
                throw new InvalidArgumentException('L’enregistrement du WebP Article a échoué.');
            }

            return [
                'webp' => $publicTargetDirectory.'/'.$filename,
                'width' => $targetWidth,
                'height' => $targetHeight,
                'fileSize' => (int) (filesize($targetFile) ?: 0),
            ];
        } finally {
            imagedestroy($targetImage);
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(
        string $publicSourcePath,
        ?string $basenameSeed = null,
        string $publicTargetDirectory = self::PUBLIC_VARIANT_DIRECTORY,
    ): array {
        $sourceFile = $this->resolvePublicFile($publicSourcePath);
        $imageSize = @getimagesize($sourceFile);
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException('L’image source est illisible.');
        }

        $mimeType = (string) $imageSize['mime'];
        if (!$this->supportsMimeType($mimeType)) {
            throw new InvalidArgumentException(sprintf('Le type "%s" ne peut pas être traité par le pipeline média.', $mimeType ?: 'inconnu'));
        }

        $sourceWidth = (int) $imageSize[0];
        $sourceHeight = (int) $imageSize[1];
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new InvalidArgumentException('Les dimensions de l’image source sont invalides.');
        }

        $variantDirectory = $this->publicDirectory($publicTargetDirectory);
        $this->ensureDirectory($variantDirectory);

        $seed = $basenameSeed ?: $publicSourcePath;
        $baseName = 'media_'.substr(hash('sha256', $seed.'|'.filesize($sourceFile).'|'.filemtime($sourceFile)), 0, 20);
        $variants = [
            'source' => [
                'path' => $publicSourcePath,
                'mimeType' => $mimeType,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
                'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
                'formats' => $this->supportedOutputFormats(),
            ],
        ];

        foreach (self::LEGACY_SIZES as $sizeName => $maxWidth) {
            $variants[$sizeName] = $this->generateSize(
                $sourceFile,
                $mimeType,
                $sourceWidth,
                $sourceHeight,
                $variantDirectory,
                $publicTargetDirectory,
                $baseName,
                $sizeName,
                $maxWidth,
            );
        }

        return $variants;
    }

    /**
     * Generate the public variants used only by MediaType::Image + ImageType::Standard.
     *
     * @return array<string, mixed>
     */
    public function generateStandard(
        string $publicSourcePath,
        ?string $basenameSeed = null,
        string $publicTargetDirectory = self::PUBLIC_VARIANT_DIRECTORY,
    ): array {
        if (!$this->supportsWebp()) {
            throw new InvalidArgumentException('Le support WebP est requis pour générer les variantes standards.');
        }

        $sourceFile = $this->resolvePublicFile($publicSourcePath);
        $imageSize = @getimagesize($sourceFile);
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException('L’image source est illisible.');
        }

        $mimeType = (string) $imageSize['mime'];
        if (!$this->supportsMimeType($mimeType)) {
            throw new InvalidArgumentException(sprintf('Le type "%s" ne peut pas être traité par le pipeline média.', $mimeType ?: 'inconnu'));
        }

        $sourceWidth = (int) $imageSize[0];
        $sourceHeight = (int) $imageSize[1];
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new InvalidArgumentException('Les dimensions de l’image source sont invalides.');
        }

        $variantDirectory = $this->publicDirectory($publicTargetDirectory);
        $this->ensureDirectory($variantDirectory);

        $seed = $basenameSeed ?: $publicSourcePath;
        $contentHash = hash_file('sha256', $sourceFile);
        if (!is_string($contentHash)) {
            throw new InvalidArgumentException('L’image source ne peut pas être identifiée.');
        }
        $baseName = 'media_'.substr(hash('sha256', $seed.'|'.filesize($sourceFile).'|'.$contentHash), 0, 20);
        $variants = [
            'source' => [
                'path' => $publicSourcePath,
                'mimeType' => $mimeType,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
                'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
                'formats' => $this->standardOutputFormats(),
            ],
        ];

        $sourceImage = $this->createImage($sourceFile, $mimeType);
        /** @var array<string, array{webp: string, width: int, height: int}> $variantsByDimensions */
        $variantsByDimensions = [];

        try {
            foreach (self::STANDARD_SIZES as $sizeName => $maxWidth) {
                $targetWidth = min($sourceWidth, $maxWidth);
                $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));
                if ($targetHeight < 1) {
                    throw new InvalidArgumentException('Les dimensions calculées de la variante sont invalides.');
                }

                $dimensionKey = $targetWidth.'x'.$targetHeight;
                if (isset($variantsByDimensions[$dimensionKey])) {
                    $variants[$sizeName] = $variantsByDimensions[$dimensionKey];

                    continue;
                }

                $variant = $this->generateStandardSize(
                    $sourceImage,
                    $mimeType,
                    $sourceWidth,
                    $sourceHeight,
                    $variantDirectory,
                    $publicTargetDirectory,
                    $baseName,
                    $sizeName,
                    $targetWidth,
                    $targetHeight,
                );
                $variantsByDimensions[$dimensionKey] = $variant;
                $variants[$sizeName] = $variant;
            }
        } finally {
            imagedestroy($sourceImage);
        }

        return $variants;
    }

    /**
     * @return array{webp: string, width: int, height: int}
     */
    private function generateStandardSize(
        GdImage $sourceImage,
        string $mimeType,
        int $sourceWidth,
        int $sourceHeight,
        string $variantDirectory,
        string $publicTargetDirectory,
        string $baseName,
        string $sizeName,
        int $targetWidth,
        int $targetHeight,
    ): array {
        $targetImage = $this->createCanvas($targetWidth, $targetHeight, $mimeType);

        try {
            if (!imagecopyresampled(
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
            )) {
                throw new InvalidArgumentException('La génération de la variante a échoué.');
            }

            $filename = sprintf('%s_%s.webp', $baseName, $sizeName);
            $targetFile = $variantDirectory.'/'.$filename;
            $temporaryFile = $targetFile.'.tmp-'.bin2hex(random_bytes(6));

            try {
                if (!imagewebp($targetImage, $temporaryFile, self::WEBP_QUALITY)) {
                    throw new InvalidArgumentException('La génération WebP a échoué.');
                }

                $generatedImageSize = @getimagesize($temporaryFile);
                if (
                    !is_array($generatedImageSize)
                    || $generatedImageSize['mime'] !== 'image/webp'
                    || (int) $generatedImageSize[0] !== $targetWidth
                    || (int) $generatedImageSize[1] !== $targetHeight
                ) {
                    throw new InvalidArgumentException('La variante WebP générée est illisible ou incomplète.');
                }

                if (!rename($temporaryFile, $targetFile)) {
                    throw new InvalidArgumentException('L’enregistrement de la variante WebP a échoué.');
                }
            } finally {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }

            return [
                'webp' => $publicTargetDirectory.'/'.$filename,
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        } finally {
            imagedestroy($targetImage);
        }
    }

    /**
     * @return array{
     *     fallback: string,
     *     fallbackFormat: string,
     *     webp?: string,
     *     avif?: string,
     *     width: int,
     *     height: int
     * }
     */
    private function generateSize(
        string $sourceFile,
        string $mimeType,
        int $sourceWidth,
        int $sourceHeight,
        string $variantDirectory,
        string $publicTargetDirectory,
        string $baseName,
        string $sizeName,
        int $maxWidth,
    ): array {
        $targetWidth = min($sourceWidth, $maxWidth);
        $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));
        if ($targetWidth < 1 || $targetHeight < 1) {
            throw new InvalidArgumentException('Les dimensions calculées de la variante sont invalides.');
        }

        $sourceImage = $this->createImage($sourceFile, $mimeType);
        try {
            $targetImage = $this->createCanvas($targetWidth, $targetHeight, $mimeType);
        } catch (\Throwable $exception) {
            imagedestroy($sourceImage);

            throw $exception;
        }

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

        $fallbackExtension = self::FALLBACK_EXTENSIONS[$mimeType];
        $fallbackFilename = sprintf('%s_%s.%s', $baseName, $sizeName, $fallbackExtension);
        $fallbackFile = $variantDirectory.'/'.$fallbackFilename;
        $this->saveImage($targetImage, $fallbackFile, $mimeType);

        $variant = [
            'fallback' => $publicTargetDirectory.'/'.$fallbackFilename,
            'fallbackFormat' => $mimeType,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];

        if ($this->supportsWebp()) {
            if ($mimeType === 'image/webp') {
                $variant['webp'] = $variant['fallback'];
            } else {
                $webpFilename = sprintf('%s_%s.webp', $baseName, $sizeName);
                $webpFile = $variantDirectory.'/'.$webpFilename;
                if (!imagewebp($targetImage, $webpFile, self::WEBP_QUALITY)) {
                    throw new InvalidArgumentException('La génération WebP a échoué.');
                }
                $variant['webp'] = $publicTargetDirectory.'/'.$webpFilename;
            }
        }

        if ($this->supportsAvif()) {
            $avifFilename = sprintf('%s_%s.avif', $baseName, $sizeName);
            $avifFile = $variantDirectory.'/'.$avifFilename;
            if (!imageavif($targetImage, $avifFile, self::AVIF_QUALITY)) {
                throw new InvalidArgumentException('La génération AVIF a échoué.');
            }
            $variant['avif'] = $publicTargetDirectory.'/'.$avifFilename;
        }

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        return $variant;
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
            throw new InvalidArgumentException('L’image source ne peut pas être ouverte.');
        }

        return $image;
    }

    private function createCanvas(int $width, int $height, string $mimeType): GdImage
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Les dimensions calculées de la variante sont invalides.');
        }

        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('La création de la variante a échoué.');
        }

        if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            if ($transparent === false) {
                imagedestroy($image);

                throw new InvalidArgumentException('La création de la variante a échoué.');
            }
            imagefilledrectangle($image, 0, 0, $width, $height, $transparent);
        } else {
            $white = imagecolorallocate($image, 255, 255, 255);
            if ($white === false) {
                imagedestroy($image);

                throw new InvalidArgumentException('La création de la variante a échoué.');
            }
            imagefilledrectangle($image, 0, 0, $width, $height, $white);
        }

        return $image;
    }

    private function saveImage(GdImage $image, string $targetFile, string $mimeType): void
    {
        $saved = match ($mimeType) {
            'image/jpeg' => $this->saveJpeg($image, $targetFile),
            'image/png' => imagepng($image, $targetFile, 5),
            'image/webp' => imagewebp($image, $targetFile, self::WEBP_QUALITY),
            default => false,
        };

        if (!$saved) {
            throw new InvalidArgumentException('L’enregistrement de la variante a échoué.');
        }
    }

    private function saveJpeg(GdImage $image, string $targetFile): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, $targetFile, self::JPEG_QUALITY);
    }

    private function resolvePublicFile(string $publicPath): string
    {
        if (preg_match('#^https?://#i', $publicPath)) {
            throw new InvalidArgumentException('Les médias externes ne sont pas traités par le pipeline local.');
        }

        if (
            !str_starts_with($publicPath, self::PUBLIC_SOURCE_DIRECTORY.'/')
            || str_contains($publicPath, '..')
            || str_contains($publicPath, '\\')
            || str_contains($publicPath, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $publicPath)
        ) {
            throw new InvalidArgumentException('Le chemin source média n’est pas autorisé.');
        }

        $publicDirectory = rtrim((string) $this->parameterBag->get('kernel.project_dir'), '/\\').'/public';
        $allowedDirectory = $publicDirectory.self::PUBLIC_SOURCE_DIRECTORY;
        $sourceFile = $publicDirectory.$publicPath;
        $realAllowedDirectory = realpath($allowedDirectory);
        $realSourceFile = realpath($sourceFile);

        if ($realAllowedDirectory === false || $realSourceFile === false || !is_file($realSourceFile)) {
            throw new InvalidArgumentException('Le fichier source est introuvable ou hors du dossier public.');
        }

        $realAllowedDirectory = rtrim($realAllowedDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (!str_starts_with($realSourceFile, $realAllowedDirectory)) {
            throw new InvalidArgumentException('Le fichier source est introuvable ou hors du dossier public.');
        }

        return $realSourceFile;
    }

    private function publicDirectory(string $publicDirectory): string
    {
        return $this->parameterBag->get('kernel.project_dir').'/public'.$publicDirectory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Le dossier de variantes ne peut pas être créé.');
        }
    }
}
