<?php

namespace App\Command;

use App\Entity\MediaAsset;
use App\Repository\MediaAssetRepository;
use App\Service\Media\ImageMetadataSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:media:sanitize-metadata',
    description: 'Detect and strip sensitive metadata from public local media images.',
)]
final class SanitizeMediaMetadataCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect files without rewriting them.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-encode every supported local image, even when no metadata marker is detected.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Restrict processing to one MediaAsset id.')
            ->addOption('missing-only', null, InputOption::VALUE_NONE, 'Only clean files where metadata markers are detected.')
            ->addOption('include-avatars', null, InputOption::VALUE_NONE, 'Also scan public/uploads/avatars.')
            ->addOption('include-demo', null, InputOption::VALUE_NONE, 'Also scan every image in public/uploads/demo, including files not referenced by MediaAsset.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $missingOnly = (bool) $input->getOption('missing-only');
        $id = $input->getOption('id');
        $includeAvatars = (bool) $input->getOption('include-avatars');
        $includeDemo = (bool) $input->getOption('include-demo');

        if ($force && $missingOnly) {
            $io->error('Les options --force et --missing-only sont contradictoires.');

            return Command::INVALID;
        }

        $targets = $this->collectTargets($id, $includeAvatars, $includeDemo);
        if ($targets === []) {
            $io->warning('Aucun fichier média local à analyser.');

            return Command::SUCCESS;
        }

        $io->title(($dryRun ? 'Audit' : 'Nettoyage').' des métadonnées images');

        $analyzed = 0;
        $cleaned = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($targets as $path => $target) {
            ++$analyzed;

            try {
                $inspection = $this->imageMetadataSanitizer->inspectPublicPath($path);
                $context = $target['mediaIds'] === []
                    ? $target['source']
                    : 'MediaAsset #'.implode(', #', $target['mediaIds']);
                $markers = $inspection['markers'] === [] ? 'aucun marqueur détecté' : implode(', ', $inspection['markers']);

                if (!$inspection['supported']) {
                    ++$skipped;
                    $io->text(sprintf('%s ignoré : type non supporté %s (%s).', $path, $inspection['mimeType'], $context));

                    continue;
                }

                if (!$force && !$inspection['hasSensitiveMetadata']) {
                    ++$skipped;
                    $io->text(sprintf('%s ignoré : %s (%s).', $path, $markers, $context));

                    continue;
                }

                if ($dryRun) {
                    ++$cleaned;
                    $io->text(sprintf('%s serait nettoyé : %s (%s).', $path, $markers, $context));

                    continue;
                }

                $result = $this->imageMetadataSanitizer->sanitizePublicPath($path, $target['applyOrientation']);
                ++$cleaned;
                $io->text(sprintf(
                    '%s nettoyé : avant %s, après %s.',
                    $path,
                    $result['markersBefore'] === [] ? 'aucun marqueur' : implode(', ', $result['markersBefore']),
                    $result['markersAfter'] === [] ? 'aucun marqueur' : implode(', ', $result['markersAfter']),
                ));

                foreach ($target['media'] as $media) {
                    if ($media->getFilePath() === $path) {
                        $media
                            ->setMimeType($result['mimeType'])
                            ->setWidth($result['width'])
                            ->setHeight($result['height']);
                    }
                }
            } catch (\Throwable $exception) {
                ++$errors;
                $io->warning(sprintf('%s erreur : %s', $path, $exception->getMessage()));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Rapport : %d analysé(s), %d nettoyé(s), %d ignoré(s), %d erreur(s).',
            $analyzed,
            $cleaned,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<string, array{
     *     source: string,
     *     mediaIds: list<int>,
     *     media: list<MediaAsset>,
     *     applyOrientation: bool
     * }>
     */
    private function collectTargets(mixed $id, bool $includeAvatars, bool $includeDemo): array
    {
        $targets = [];

        foreach ($this->resolveMediaAssets($id) as $media) {
            $applyOrientation = !in_array($media->getImageType()?->value, ['360', '180'], true);

            foreach ($this->mediaPaths($media) as $path) {
                $this->addTarget($targets, $path, 'media', $media, $applyOrientation);
            }
        }

        if ($includeAvatars) {
            foreach ($this->publicUploadFiles('/uploads/avatars') as $path) {
                $this->addTarget($targets, $path, 'avatar', null, true);
            }
        }

        if ($includeDemo) {
            foreach ($this->publicUploadFiles('/uploads/demo') as $path) {
                $this->addTarget($targets, $path, 'demo', null, false);
            }
        }

        ksort($targets);

        return $targets;
    }

    /** @return list<MediaAsset> */
    private function resolveMediaAssets(mixed $id): array
    {
        if ($id !== null && trim((string) $id) !== '') {
            $media = $this->mediaAssetRepository->find((int) $id);

            return $media instanceof MediaAsset ? [$media] : [];
        }

        return $this->mediaAssetRepository->findBy([], ['id' => 'ASC']);
    }

    /** @return list<string> */
    private function mediaPaths(MediaAsset $media): array
    {
        $paths = [];
        foreach ([$media->getFilePath(), $media->getThumbnailPath()] as $path) {
            if ($this->isLocalUploadPath($path)) {
                $paths[] = (string) $path;
            }
        }

        $this->collectVariantPaths($media->getVariants(), $paths);

        return array_values(array_unique($paths));
    }

    /** @param list<string> $paths */
    private function collectVariantPaths(mixed $value, array &$paths): void
    {
        if (is_string($value)) {
            if ($this->isLocalUploadPath($value)) {
                $paths[] = $value;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->collectVariantPaths($child, $paths);
        }
    }

    /**
     * @param array<string, array{
     *     source: string,
     *     mediaIds: list<int>,
     *     media: list<MediaAsset>,
     *     applyOrientation: bool
     * }> $targets
     */
    private function addTarget(array &$targets, string $path, string $source, ?MediaAsset $media, bool $applyOrientation): void
    {
        if (!isset($targets[$path])) {
            $targets[$path] = [
                'source' => $source,
                'mediaIds' => [],
                'media' => [],
                'applyOrientation' => $applyOrientation,
            ];
        }

        $targets[$path]['applyOrientation'] = $targets[$path]['applyOrientation'] && $applyOrientation;

        if ($media instanceof MediaAsset) {
            $id = $media->getId();
            if ($id !== null && !in_array($id, $targets[$path]['mediaIds'], true)) {
                $targets[$path]['mediaIds'][] = $id;
            }

            $targets[$path]['media'][] = $media;
        }
    }

    /** @return list<string> */
    private function publicUploadFiles(string $publicDirectory): array
    {
        $directory = rtrim($this->projectDir, '/').'/public'.$publicDirectory;
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $paths[] = $this->imageMetadataSanitizer->publicPathForAbsolutePath($file->getPathname());
        }

        return $paths;
    }

    private function isLocalUploadPath(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, '/uploads/')
            && !str_starts_with($path, 'http://')
            && !str_starts_with($path, 'https://');
    }
}
