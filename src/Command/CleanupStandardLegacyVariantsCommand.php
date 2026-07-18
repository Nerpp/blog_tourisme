<?php

namespace App\Command;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Repository\MediaAssetRepository;
use App\Service\Media\StandardLegacyVariantCleanupService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:cleanup-standard-legacy-variants',
    description: 'Remove legacy JPG, PNG and AVIF variant files from standard image media.',
)]
final class CleanupStandardLegacyVariantsCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly StandardLegacyVariantCleanupService $standardLegacyVariantCleanupService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List legacy standard variant files without deleting them.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Restrict cleanup to one media asset id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $mediaId = $this->positiveIntOption($input->getOption('id'), '--id');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $mediaAssets = $this->resolveMediaAssets($mediaId);
        if ($mediaAssets === []) {
            $io->warning('Aucun média trouvé.');

            return Command::SUCCESS;
        }

        $io->title($dryRun ? 'Audit des anciennes variantes standards' : 'Nettoyage des anciennes variantes standards');

        $analyzed = 0;
        $deletable = 0;
        $deleted = 0;
        $skipped = 0;
        $metadataOnly = 0;
        $bytes = 0;

        foreach ($mediaAssets as $media) {
            ++$analyzed;
            $result = $this->standardLegacyVariantCleanupService->cleanup($media, $dryRun, pruneMetadata: true);

            if ($result['skipped']) {
                ++$skipped;
                $io->text(sprintf('#%d ignoré : %s', $media->getId() ?? 0, $result['reason'] ?? 'hors périmètre'));

                continue;
            }

            $hasFileOutput = false;
            foreach ($result['files'] as $file) {
                $hasFileOutput = true;
                if ($file['reason'] === null && !$file['missing']) {
                    ++$deletable;
                    $bytes += $file['bytes'];
                }

                if ($file['deleted']) {
                    ++$deleted;
                }

                $io->text(sprintf(
                    '#%d %s : %s (%s)',
                    $media->getId() ?? 0,
                    $this->fileStatus($file, $dryRun),
                    $file['path'],
                    $this->formatBytes($file['bytes']),
                ));
            }

            if ($result['metadataChanged'] && !$hasFileOutput) {
                ++$metadataOnly;
                $io->text(sprintf(
                    '#%d %s : métadonnées standard',
                    $media->getId() ?? 0,
                    $dryRun ? 'à nettoyer' : 'nettoyées',
                ));
            } elseif ($result['metadataChanged']) {
                ++$metadataOnly;
                $io->text(sprintf(
                    '#%d %s : métadonnées standard',
                    $media->getId() ?? 0,
                    $dryRun ? 'à nettoyer' : 'nettoyées',
                ));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Rapport : %d média(s) analysé(s), %d fichier(s) supprimable(s), %d fichier(s) supprimé(s), %d média(s) avec métadonnées nettoyées, %d ignoré(s), espace %s.',
            $analyzed,
            $deletable,
            $deleted,
            $metadataOnly,
            $skipped,
            $this->formatBytes($bytes),
        ));

        return Command::SUCCESS;
    }

    /** @return list<MediaAsset> */
    private function resolveMediaAssets(?int $id): array
    {
        if ($id !== null) {
            $media = $this->mediaAssetRepository->find($id);

            return $media instanceof MediaAsset ? [$media] : [];
        }

        return $this->mediaAssetRepository->findBy([
            'mediaType' => MediaType::Image,
            'imageType' => ImageType::Standard,
        ], ['id' => 'ASC']);
    }

    private function positiveIntOption(mixed $value, string $option): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }

            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        $digits = ltrim($value, '0');
        if (
            !ctype_digit($value) || $digits === '' || strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)
        ) {
            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        return (int) $value;
    }

    /** @param array{deleted: bool, missing: bool, reason: string|null} $file */
    private function fileStatus(array $file, bool $dryRun): string
    {
        if ($file['missing']) {
            return 'absent';
        }

        if ($file['reason'] !== null) {
            return 'ignoré';
        }

        if ($dryRun) {
            return 'supprimable';
        }

        return $file['deleted'] ? 'supprimé' : 'conservé';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d o', $bytes);
        }

        if ($bytes < 1_048_576) {
            return sprintf('%.1f Ko', $bytes / 1024);
        }

        return sprintf('%.1f Mo', $bytes / 1_048_576);
    }
}
