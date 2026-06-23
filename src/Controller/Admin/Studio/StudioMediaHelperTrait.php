<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraftMedia;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Entity\PlaceMedia;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\VideoType;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * @property-read \App\Service\Media\ImageTypeDetector $imageTypeDetector
 * @property-read \App\Service\Media\VideoThumbnailGenerator $videoThumbnailGenerator
 * @property-read \App\Service\Media\BulkMediaUploadService $bulkMediaUploadService
 * @property-read \App\Service\Media\PublicMediaMasterCleanupService $publicMediaMasterCleanupService
 */
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

        return array_values(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    private function createImageAssetFromUpload(
        UploadedFile $file,
        ?string $caption = null,
        ?ImageType $imageType = null,
        object|string|null $context = null,
        ?string &$error = null,
    ): ?MediaAsset {
        $error = null;
        $imageType ??= $this->imageTypeDetector->detectFromUpload($file);

        try {
            $storedFile = $this->storeImageByType($file, $imageType, $context);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $this->addFlash('warning', sprintf('Image "%s" ignorée : %s', $file->getClientOriginalName(), $exception->getMessage()));

            return null;
        }

        $media = (new MediaAsset())
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setTitle($this->truncate($storedFile['title'], 180))
            ->setAltText($storedFile['altText'])
            ->setCaption($this->nullIfBlank($caption))
            ->setMediaType(MediaType::Image)
            ->setImageType($imageType)
            ->setFilePath($storedFile['path'])
            ->setThumbnailPath($storedFile['thumbnailPath'] ?? null)
            ->setMimeType($storedFile['mimeType'])
            ->setFileSize($storedFile['fileSize'])
            ->setWidth($storedFile['width'])
            ->setHeight($storedFile['height'])
            ->setProjection($storedFile['projection'] ?? null)
            ->setMetadata($storedFile['metadata'] ?? null);

        $variantResult = $this->mediaVariantService->generateForMedia($media);
        if ($variantResult['status'] === 'error') {
            $this->addFlash('warning', 'L’image a été ajoutée, mais ses variantes responsive n’ont pas pu être générées.');
        } else {
            $this->publicMediaMasterCleanupService->cleanupIfSafe($media);
        }

        return $media;
    }

    private function wantsJsonUploadResponse(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept'), 'application/json')
            || $request->request->getBoolean('ajax');
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function uploadJsonResponse(array $results, int $status = 200): JsonResponse
    {
        $successes = array_values(array_filter($results, static fn (array $result): bool => ($result['success'] ?? false) === true));
        $failures = count($results) - count($successes);
        $firstResult = $results[0] ?? ['success' => false, 'error' => 'Aucun fichier reçu.'];

        return $this->json($firstResult + [
            'results' => $results,
            'sent' => count($successes),
            'failed' => $failures,
            'total' => count($results),
        ], $status);
    }

    private function createVideoAssetFromRequest(Request $request, object|string|null $context = null): ?MediaAsset
    {
        $externalUrl = $this->nullIfBlank($request->request->getString('externalUrl'));
        if ($externalUrl === null) {
            return null;
        }

        $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? VideoType::External;
        if ($videoType === VideoType::Local) {
            $videoType = VideoType::External;
        }

        $media = (new MediaAsset())
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setTitle(
                $this->nullIfBlank($request->request->getString('title'))
                ?? $this->mediaSeoTextService->titleForContext($context, MediaType::Video),
            )
            ->setAltText($this->mediaSeoTextService->altTextForContext($context, MediaType::Video))
            ->setCaption($this->nullIfBlank($request->request->getString('caption')))
            ->setMediaType(MediaType::Video)
            ->setVideoType($videoType)
            ->setExternalUrl($externalUrl);

        $this->videoThumbnailGenerator->generateForMedia($media);

        return $media;
    }

    private function updateSimpleMediaAssetFromRequest(MediaAsset $media, Request $request): void
    {
        $media
            ->setTitle($this->nullIfBlank($request->request->getString('title')))
            ->setCaption($this->nullIfBlank($request->request->getString('caption')));

        if ($media->getMediaType() === MediaType::Image) {
            $media->setAltText($this->nullIfBlank($request->request->getString('altText')));
        }

        if ($media->getMediaType() === MediaType::Video) {
            $previousExternalUrl = $media->getExternalUrl();
            $externalUrl = $this->nullIfBlank($request->request->getString('externalUrl'));
            $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? $media->getVideoType() ?? VideoType::External;
            $media
                ->setVideoType($videoType === VideoType::Local ? VideoType::External : $videoType)
                ->setExternalUrl($externalUrl);

            if ($externalUrl !== $previousExternalUrl) {
                $media->setThumbnailPath(null);
            }

            if ($media->getThumbnailPath() === null || $media->getThumbnailPath() === '') {
                $this->videoThumbnailGenerator->generateForMedia($media);
            }
        }
    }

    /** @param iterable<HikeDraftMedia|CityVisitDraftMedia|PlaceMedia> $mediaLinks */
    private function promoteClassicImageToCover(
        iterable $mediaLinks,
        HikeDraftMedia|CityVisitDraftMedia|PlaceMedia $selectedLink,
    ): void
    {
        $mediaLinks = is_array($mediaLinks) ? $mediaLinks : iterator_to_array($mediaLinks, false);
        if (!in_array($selectedLink, $mediaLinks, true)) {
            return;
        }

        if (!$this->isClassicImageMediaLink($selectedLink)) {
            $selectedLink->setRole(MediaRole::Gallery);

            return;
        }

        $this->normalizeClassicCoverImages($mediaLinks, $selectedLink);
    }

    /** @param iterable<HikeDraftMedia|CityVisitDraftMedia|PlaceMedia> $mediaLinks */
    private function normalizeClassicCoverImages(
        iterable $mediaLinks,
        HikeDraftMedia|CityVisitDraftMedia|PlaceMedia|null $selectedCoverLink = null,
    ): void
    {
        $mediaLinks = is_array($mediaLinks) ? $mediaLinks : iterator_to_array($mediaLinks, false);
        if ($selectedCoverLink !== null && !in_array($selectedCoverLink, $mediaLinks, true)) {
            return;
        }

        $keptCoverLink = $selectedCoverLink;

        foreach ($mediaLinks as $mediaLink) {
            if (!$this->isClassicImageMediaLink($mediaLink)) {
                continue;
            }

            if ($selectedCoverLink !== null) {
                if ($mediaLink === $selectedCoverLink) {
                    $mediaLink->setRole(MediaRole::Cover);
                } elseif ($mediaLink->getRole() === MediaRole::Cover) {
                    $mediaLink->setRole(MediaRole::Gallery);
                }

                continue;
            }

            if ($mediaLink->getRole() !== MediaRole::Cover) {
                continue;
            }

            if ($keptCoverLink === null) {
                $keptCoverLink = $mediaLink;
                continue;
            }

            $mediaLink->setRole(MediaRole::Gallery);
        }
    }

    private function isValidStudioMediaAssociation(mixed $association, ?object $targetPoint, bool $allowMain): bool
    {
        $association = trim((string) $association);
        if ($association === 'gallery' || ($allowMain && $association === 'main')) {
            return true;
        }

        return str_starts_with($association, 'point:') && $targetPoint !== null;
    }

    private function isClassicImageMediaLink(HikeDraftMedia|CityVisitDraftMedia|PlaceMedia $mediaLink): bool
    {
        $media = $mediaLink->getMediaAsset();

        return $media instanceof MediaAsset && $media->getMediaType() === MediaType::Image;
    }

    /**
     * @return array{title: string, altText: string, path: string, thumbnailPath?: string|null, mimeType: string|null, fileSize: int|null, width: int|null, height: int|null, projection?: string|null, metadata?: array<string, mixed>|null}
     */
    private function storeUploadedImage(UploadedFile $file, object|string|null $context = null, ?ImageType $imageType = null): array
    {
        $imageType ??= ImageType::Standard;
        $inspection = $this->imageUploadSecurity->inspect($file);
        $extension = $inspection['extension'];
        $safeName = $this->mediaSeoTextService->filenameBaseForContext($context, MediaType::Image, $imageType);
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $extension);
        $targetDirectory = $this->parameterBag->get('kernel.project_dir').'/public/'.self::UPLOAD_DIRECTORY;
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $file->move($targetDirectory, $filename);
        $sanitized = $this->imageMetadataSanitizer->sanitizePublicPath('/'.self::UPLOAD_DIRECTORY.'/'.$filename);

        return [
            'title' => $this->mediaSeoTextService->titleForContext($context, MediaType::Image, $imageType),
            'altText' => $this->mediaSeoTextService->altTextForContext($context, MediaType::Image, $imageType),
            'path' => '/'.self::UPLOAD_DIRECTORY.'/'.$filename,
            'mimeType' => $sanitized['mimeType'],
            'fileSize' => (int) (filesize($targetDirectory.'/'.$filename) ?: $inspection['fileSize']),
            'width' => $sanitized['width'],
            'height' => $sanitized['height'],
        ];
    }

    /**
     * @return array{title: string, altText: string, path: string, thumbnailPath?: string|null, mimeType: string|null, fileSize: int|null, width: int|null, height: int|null, projection?: string|null, metadata?: array<string, mixed>|null}
     */
    private function storeImageByType(UploadedFile $file, ImageType $imageType, object|string|null $context = null): array
    {
        if ($imageType === ImageType::Degree360) {
            $storedFile = $this->panoramaUploadService->upload(
                $file,
                $this->mediaSeoTextService->filenameBaseForContext($context, MediaType::Image, $imageType),
            );

            return array_replace($storedFile, [
                'title' => $this->mediaSeoTextService->titleForContext($context, MediaType::Image, $imageType),
                'altText' => $this->mediaSeoTextService->altTextForContext($context, MediaType::Image, $imageType),
            ]);
        }

        return $this->storeUploadedImage($file, $context, $imageType);
    }

    private function consumeUploadRateLimit(Request $request): bool
    {
        $limit = $this->actionRateLimiter->consumeAdminUpload(
            $request,
            $this->getUser() instanceof User ? $this->getUser() : null,
        );

        if ($limit->isAccepted()) {
            return true;
        }

        $this->addUploadRateLimitFlash($limit);

        return false;
    }

    private function addUploadRateLimitFlash(RateLimit $limit): void
    {
        $this->addFlash('warning', sprintf(
            'Trop d’envois de médias. Réessayez à partir de %s.',
            $limit->getRetryAfter()->format('H:i'),
        ));
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
     * @param list<CityVisitDraftStatus|HikeDraftStatus|HikePointType|ImageType|VideoType> $cases
     * @param array<int|string, string> $labels
     *
     * @return array<int|string, string>
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
