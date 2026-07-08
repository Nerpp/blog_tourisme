<?php

namespace App\Service;

use App\Service\Media\BulkMediaUploadService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageUploadSecurity
{
    private const MAX_WIDTH = 10_000;
    private const MAX_HEIGHT = 10_000;
    private const MAX_PIXELS = 60_000_000;

    /** @var array<string, list<string>> */
    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @var array<string, string> */
    private const CANONICAL_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
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
        if ($fileSize === false || $fileSize <= 0) {
            throw new InvalidArgumentException('le fichier est vide.');
        }

        if ($fileSize > BulkMediaUploadService::CLASSIC_MAX_BYTES) {
            throw new InvalidArgumentException('la taille maximale autorisée est 30 Mo pour une photo classique.');
        }

        $clientExtension = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($clientExtension === '' || !$this->isAllowedExtension($clientExtension)) {
            throw new InvalidArgumentException('seuls les fichiers JPG, PNG et WebP sont acceptés.');
        }

        $mimeType = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIME_EXTENSIONS[$mimeType])) {
            throw new InvalidArgumentException('seuls les fichiers JPG, PNG et WebP sont acceptés.');
        }

        if (!in_array($clientExtension, self::ALLOWED_MIME_EXTENSIONS[$mimeType], true)) {
            throw new InvalidArgumentException('l’extension du fichier ne correspond pas au type réel de l’image.');
        }

        $imageSize = @getimagesize($file->getPathname());
        if (!is_array($imageSize)) {
            throw new InvalidArgumentException('le contenu du fichier n’est pas une image lisible.');
        }

        $detectedMime = (string) $imageSize['mime'];
        if ($detectedMime === '' || !array_key_exists($detectedMime, self::ALLOWED_MIME_EXTENSIONS) || $detectedMime !== $mimeType) {
            throw new InvalidArgumentException('le type réel de l’image ne correspond pas au fichier envoyé.');
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if (
            $width < 1
            || $height < 1
            || $width > self::MAX_WIDTH
            || $height > self::MAX_HEIGHT
            || ($width * $height) > self::MAX_PIXELS
        ) {
            throw new InvalidArgumentException('les dimensions de l’image sont invalides ou trop grandes.');
        }

        return [
            'mimeType' => $mimeType,
            'fileSize' => $fileSize,
            'width' => $width,
            'height' => $height,
            'extension' => self::CANONICAL_EXTENSIONS[$mimeType],
        ];
    }

    private function isAllowedExtension(string $extension): bool
    {
        foreach (self::ALLOWED_MIME_EXTENSIONS as $extensions) {
            if (in_array($extension, $extensions, true)) {
                return true;
            }
        }

        return false;
    }
}
