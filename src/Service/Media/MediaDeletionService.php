<?php

namespace App\Service\Media;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPointMedia;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class MediaDeletionService
{
    /** @var list<class-string> */
    private const LINK_ENTITIES = [
        ArticleMedia::class,
        PlaceMedia::class,
        HikeDraftMedia::class,
        CityVisitDraftMedia::class,
        HikePointMedia::class,
        CityVisitPointMedia::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{orphan: bool, usageCount: int} */
    public function usage(MediaAsset $media): array
    {
        $usageCount = 0;

        foreach (self::LINK_ENTITIES as $entityClass) {
            $usageCount += (int) $this->entityManager
                ->getRepository($entityClass)
                ->count(['mediaAsset' => $media]);
        }

        $usageCount += (int) $this->entityManager
            ->getRepository(Article::class)
            ->count(['featuredImage' => $media]);
        $usageCount += (int) $this->entityManager
            ->getRepository(Place::class)
            ->count(['featuredImage' => $media]);

        return [
            'orphan' => $usageCount === 0,
            'usageCount' => $usageCount,
        ];
    }

    /**
     * @return array{
     *     mediaId: int|null,
     *     deleted: bool,
     *     skipped: bool,
     *     reason: ?string,
     *     files: list<string>
     * }
     */
    public function deleteIfOrphan(MediaAsset $media, bool $dryRun = false): array
    {
        $usage = $this->usage($media);
        if (!$usage['orphan']) {
            return [
                'mediaId' => $media->getId(),
                'deleted' => false,
                'skipped' => true,
                'reason' => sprintf('encore utilisé %d fois', $usage['usageCount']),
                'files' => [],
            ];
        }

        if ($media->getExternalUrl() !== null && $media->getFilePath() === null && $media->getThumbnailPath() === null) {
            return [
                'mediaId' => $media->getId(),
                'deleted' => false,
                'skipped' => true,
                'reason' => 'média externe sans fichier local',
                'files' => [],
            ];
        }

        $files = $this->localUploadFiles($media);
        if (!$dryRun) {
            foreach ($files as $file) {
                $this->deleteFile($file);
            }

            $this->entityManager->remove($media);
        }

        return [
            'mediaId' => $media->getId(),
            'deleted' => !$dryRun,
            'skipped' => false,
            'reason' => $dryRun ? 'dry-run' : null,
            'files' => $files,
        ];
    }

    /** @return list<string> */
    public function localUploadFiles(MediaAsset $media): array
    {
        $paths = array_filter([
            $media->getFilePath(),
            $media->getThumbnailPath(),
            ...$this->variantPaths($media->getVariants()),
            ...$this->metadataPaths($media->getMetadata()),
        ], static fn (?string $path): bool => is_string($path) && $path !== '');

        $files = [];
        foreach (array_unique($paths) as $path) {
            $absolutePath = $this->safeAbsoluteUploadPath((string) $path);
            if ($absolutePath !== null && is_file($absolutePath)) {
                $files[] = $absolutePath;
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function variantPaths(mixed $variants): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $paths = [];
        array_walk_recursive($variants, static function (mixed $value) use (&$paths): void {
            if (is_string($value) && str_starts_with($value, '/uploads/')) {
                $paths[] = $value;
            }
        });

        return $paths;
    }

    /** @return list<string> */
    private function metadataPaths(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $paths = [];
        foreach (['originalPath', 'mobilePath'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && str_starts_with($value, '/uploads/')) {
                $paths[] = $value;
            }
        }

        return $paths;
    }

    private function safeAbsoluteUploadPath(string $publicPath): ?string
    {
        if (
            str_starts_with($publicPath, 'http://')
            || str_starts_with($publicPath, 'https://')
            || str_starts_with($publicPath, '/uploads/demo/')
            || !str_starts_with($publicPath, '/uploads/')
            || basename($publicPath) === '.gitkeep'
        ) {
            return null;
        }

        $publicDirectory = $this->parameterBag->get('kernel.project_dir').'/public';
        $absolutePath = $publicDirectory.$publicPath;
        $realPublicUploads = realpath($publicDirectory.'/uploads');
        $realPath = realpath($absolutePath);

        if ($realPublicUploads === false || $realPath === false || !str_starts_with($realPath, $realPublicUploads.'/')) {
            return null;
        }

        return $realPath;
    }

    private function deleteFile(string $absolutePath): void
    {
        if (!is_file($absolutePath)) {
            return;
        }

        if (!@unlink($absolutePath)) {
            $this->logger->warning('Impossible de supprimer un fichier média orphelin.', [
                'path' => $absolutePath,
            ]);
        }
    }
}
