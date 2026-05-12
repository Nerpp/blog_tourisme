<?php

namespace App\Service;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageUploadSecurity
{
    private const MAX_BYTES = 8_388_608;
    private const MAX_PIXELS = 40_000_000;

    /** @var array<string, string> */
    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @return array{mimeType: string, fileSize: int, width: int, height: int, extension: string}
     */
    public function inspect(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('le transfert est incomplet ou refusé par PHP.');
        }

        $fileSize = $file->getSize();
        if ($fileSize === null || $fileSize <= 0) {
            throw new InvalidArgumentException('le fichier est vide.');
        }

        if ($fileSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('la taille maximale autorisée est 8 Mo.');
        }

        $mimeType = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIME_EXTENSIONS[$mimeType])) {
            throw new InvalidArgumentException('seuls les fichiers JPEG, PNG, WebP et GIF sont acceptés.');
        }

        $imageSize = @getimagesize($file->getPathname());
        if (!is_array($imageSize) || !isset($imageSize[0], $imageSize[1])) {
            throw new InvalidArgumentException('le contenu du fichier n’est pas une image lisible.');
        }

        $detectedMime = (string) ($imageSize['mime'] ?? '');
        if ($detectedMime === '' || !isset(self::ALLOWED_MIME_EXTENSIONS[$detectedMime]) || $detectedMime !== $mimeType) {
            throw new InvalidArgumentException('le type réel de l’image ne correspond pas au fichier envoyé.');
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('les dimensions de l’image sont invalides ou trop grandes.');
        }

        return [
            'mimeType' => $mimeType,
            'fileSize' => $fileSize,
            'width' => $width,
            'height' => $height,
            'extension' => self::ALLOWED_MIME_EXTENSIONS[$mimeType],
        ];
    }
}
