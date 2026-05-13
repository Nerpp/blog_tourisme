<?php

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AvatarUploadService
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_PIXELS = 20_000_000;
    private const AVATAR_SIZE = 256;
    private const UPLOAD_DIRECTORY = 'public/uploads/avatars';
    private const PUBLIC_DIRECTORY = '/uploads/avatars';

    /** @var array<string, true> */
    private const ALLOWED_EXTENSIONS = [
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
        'webp' => true,
    ];

    /** @var array<string, true> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function upload(UploadedFile $file): string
    {
        $inspection = $this->inspect($file);

        if (!$this->gdSupports($inspection['mimeType'])) {
            throw new RuntimeException('Le redimensionnement des avatars nécessite l’extension PHP GD avec support JPEG, PNG et WebP.');
        }

        $targetDirectory = $this->projectDir.'/'.self::UPLOAD_DIRECTORY;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Le dossier de stockage des avatars est indisponible.');
        }

        $filename = sprintf('avatar_%s.webp', bin2hex(random_bytes(16)));
        $targetPath = $targetDirectory.'/'.$filename;

        $source = $this->createImageResource($file->getPathname(), $inspection['mimeType']);
        $avatar = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        if ($source === false || $avatar === false) {
            throw new InvalidArgumentException('L’image de profil ne peut pas être lue.');
        }

        imagealphablending($avatar, true);
        imagesavealpha($avatar, true);

        $sourceWidth = $inspection['width'];
        $sourceHeight = $inspection['height'];
        $side = min($sourceWidth, $sourceHeight);
        $srcX = intdiv($sourceWidth - $side, 2);
        $srcY = intdiv($sourceHeight - $side, 2);

        $resized = imagecopyresampled(
            $avatar,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::AVATAR_SIZE,
            self::AVATAR_SIZE,
            $side,
            $side,
        );

        if (!$resized || !imagewebp($avatar, $targetPath, 86)) {
            imagedestroy($source);
            imagedestroy($avatar);

            throw new RuntimeException('L’avatar n’a pas pu être enregistré.');
        }

        imagedestroy($source);
        imagedestroy($avatar);

        return self::PUBLIC_DIRECTORY.'/'.$filename;
    }

    /**
     * @return array{mimeType: string, width: int, height: int}
     */
    private function inspect(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Le transfert de l’image est incomplet.');
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new InvalidArgumentException('Le fichier envoyé est vide.');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('L’image de profil ne doit pas dépasser 2 Mo.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!isset(self::ALLOWED_EXTENSIONS[$extension])) {
            throw new InvalidArgumentException('Formats acceptés : JPG, PNG ou WebP.');
        }

        $mimeType = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new InvalidArgumentException('Le type du fichier n’est pas autorisé.');
        }

        $imageSize = @getimagesize($file->getPathname());
        if (!is_array($imageSize) || !isset($imageSize[0], $imageSize[1], $imageSize['mime'])) {
            throw new InvalidArgumentException('Le fichier envoyé n’est pas une image valide.');
        }

        $detectedMime = (string) $imageSize['mime'];
        if ($detectedMime !== $mimeType || !isset(self::ALLOWED_MIME_TYPES[$detectedMime])) {
            throw new InvalidArgumentException('Le contenu réel de l’image ne correspond pas au fichier envoyé.');
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if ($width < 64 || $height < 64) {
            throw new InvalidArgumentException('L’image de profil doit mesurer au moins 64 px de côté.');
        }

        if (($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Les dimensions de l’image sont trop grandes.');
        }

        return [
            'mimeType' => $mimeType,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function gdSupports(string $mimeType): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return false;
        }

        return match ($mimeType) {
            'image/jpeg' => function_exists('imagecreatefromjpeg'),
            'image/png' => function_exists('imagecreatefrompng'),
            'image/webp' => function_exists('imagecreatefromwebp'),
            default => false,
        };
    }

    /** @return \GdImage|false */
    private function createImageResource(string $path, string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        };
    }
}
