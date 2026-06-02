<?php

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AvatarUploadService
{
    private const MAX_BYTES = 5_242_880;
    private const MAX_WIDTH = 6000;
    private const MAX_HEIGHT = 6000;
    private const AVATAR_SIZE = 256;
    private const WEBP_QUALITY = 82;
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
        if ($source === false) {
            throw new InvalidArgumentException('L’image de profil ne peut pas être lue.');
        }

        $avatar = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        if ($avatar === false) {
            imagedestroy($source);

            throw new RuntimeException('L’avatar n’a pas pu être préparé.');
        }

        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        imagefill($avatar, 0, 0, imagecolorallocatealpha($avatar, 0, 0, 0, 127));

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

        if (!$resized || !imagewebp($avatar, $targetPath, self::WEBP_QUALITY)) {
            imagedestroy($source);
            imagedestroy($avatar);
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            throw new RuntimeException('L’avatar n’a pas pu être enregistré.');
        }

        imagedestroy($source);
        imagedestroy($avatar);

        return self::PUBLIC_DIRECTORY.'/'.$filename;
    }

    public function delete(?string $publicPath): void
    {
        if ($publicPath === null || !str_starts_with($publicPath, self::PUBLIC_DIRECTORY.'/')) {
            return;
        }

        $filename = basename($publicPath);
        if ($filename === '' || $filename === '.gitkeep') {
            return;
        }

        $directory = realpath($this->projectDir.'/'.self::UPLOAD_DIRECTORY);
        if ($directory === false) {
            return;
        }

        $targetPath = realpath($directory.'/'.$filename);
        if ($targetPath === false || !str_starts_with($targetPath, $directory.DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($targetPath)) {
            @unlink($targetPath);
        }
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
        if ($size === false || $size <= 0) {
            throw new InvalidArgumentException('Le fichier envoyé est vide.');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('L’image de profil ne doit pas dépasser 5 Mo.');
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
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException('Le fichier envoyé n’est pas une image valide.');
        }

        $detectedMime = (string) $imageSize['mime'];
        if ($detectedMime !== $mimeType || !array_key_exists($detectedMime, self::ALLOWED_MIME_TYPES)) {
            throw new InvalidArgumentException('Le contenu réel de l’image ne correspond pas au fichier envoyé.');
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if ($width < 64 || $height < 64) {
            throw new InvalidArgumentException('L’image de profil doit mesurer au moins 64 px de côté.');
        }

        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            throw new InvalidArgumentException('L’image de profil ne doit pas dépasser 6000 x 6000 px.');
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
