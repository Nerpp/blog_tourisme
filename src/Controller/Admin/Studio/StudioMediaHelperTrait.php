<?php

namespace App\Controller\Admin\Studio;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

trait StudioMediaHelperTrait
{
    private const UPLOAD_DIRECTORY = 'uploads/media';

    /** @var array<string, bool> */
    private array $studioTableExistenceCache = [];

    /**
     * @param mixed $files
     *
     * @return list<UploadedFile>
     */
    private function normalizeUploadedFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile && $file->isValid()));
    }

    private function createImageAssetFromUpload(UploadedFile $file, ?string $caption = null, ?ImageType $imageType = null): ?MediaAsset
    {
        if (!str_starts_with((string) $file->getMimeType(), 'image/')) {
            return null;
        }

        $storedFile = $this->storeUploadedImage($file);

        return (new MediaAsset())
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setTitle($this->truncate($storedFile['title'], 180))
            ->setCaption($this->nullIfBlank($caption))
            ->setMediaType(MediaType::Image)
            ->setImageType($imageType ?? ImageType::Standard)
            ->setFilePath($storedFile['path'])
            ->setMimeType($storedFile['mimeType'])
            ->setFileSize($storedFile['fileSize'])
            ->setWidth($storedFile['width'])
            ->setHeight($storedFile['height']);
    }

    private function createVideoAssetFromRequest(Request $request): ?MediaAsset
    {
        $externalUrl = $this->nullIfBlank($request->request->getString('externalUrl'));
        if ($externalUrl === null) {
            return null;
        }

        $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? VideoType::External;
        if ($videoType === VideoType::Local) {
            $videoType = VideoType::External;
        }

        return (new MediaAsset())
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setTitle($this->nullIfBlank($request->request->getString('title')))
            ->setCaption($this->nullIfBlank($request->request->getString('caption')))
            ->setMediaType(MediaType::Video)
            ->setVideoType($videoType)
            ->setExternalUrl($externalUrl);
    }

    /**
     * @return array{title: string, path: string, mimeType: string|null, fileSize: int|null, width: int|null, height: int|null}
     */
    private function storeUploadedImage(UploadedFile $file): array
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'photo';
        $safeName = strtolower((string) $this->slugger->slug($originalName));
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $extension);
        $targetDirectory = $this->parameterBag->get('kernel.project_dir').'/public/'.self::UPLOAD_DIRECTORY;
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();
        $file->move($targetDirectory, $filename);

        $absolutePath = $targetDirectory.'/'.$filename;
        $imageSize = @getimagesize($absolutePath);

        return [
            'title' => $originalName,
            'path' => '/'.self::UPLOAD_DIRECTORY.'/'.$filename,
            'mimeType' => $mimeType,
            'fileSize' => $fileSize,
            'width' => is_array($imageSize) ? (int) $imageSize[0] : null,
            'height' => is_array($imageSize) ? (int) $imageSize[1] : null,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    /** @return array<int, mixed> */
    private function requestArray(Request $request, string $key): array
    {
        $data = $request->request->all();
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function truncate(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    /**
     * @param array<int, \BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function enumChoices(array $cases, array $labels): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $labels[$case->value] ?? $case->value;
        }

        return $choices;
    }

    private function databaseTableExists(string $tableName): bool
    {
        if (!array_key_exists($tableName, $this->studioTableExistenceCache)) {
            $this->studioTableExistenceCache[$tableName] = $this->entityManager
                ->getConnection()
                ->createSchemaManager()
                ->tablesExist([$tableName]);
        }

        return $this->studioTableExistenceCache[$tableName];
    }
}
