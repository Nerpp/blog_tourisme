<?php

namespace App\Command;

use App\Entity\MediaAsset;
use App\Repository\MediaAssetRepository;
use App\Service\Media\PublicMediaMasterCleanupService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:cleanup-public-masters',
    description: 'Remove public master files for classic image media when responsive variants are available.',
)]
final class CleanupPublicMediaMastersCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly PublicMediaMasterCleanupService $publicMediaMasterCleanupService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List deletable public masters without deleting them.')
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

        $io->title($dryRun ? 'Audit des fichiers maîtres publics' : 'Nettoyage des fichiers maîtres publics');

        $analyzed = 0;
        $deletable = 0;
        $deleted = 0;
        $skipped = 0;
        $bytes = 0;

        foreach ($mediaAssets as $media) {
            ++$analyzed;
            $result = $this->publicMediaMasterCleanupService->cleanupIfSafe($media, $dryRun);

            if (!$result['skipped']) {
                ++$deletable;
                $bytes += $result['bytes'];
                $io->text(sprintf(
                    '#%d %s : %s (%s)',
                    $media->getId() ?? 0,
                    $dryRun ? 'supprimable' : 'supprimé',
                    $result['path'] ?? '-',
                    $this->formatBytes($result['bytes']),
                ));

                if ($result['deleted']) {
                    ++$deleted;
                }

                continue;
            }

            ++$skipped;
            $io->text(sprintf(
                '#%d ignoré : %s%s',
                $media->getId() ?? 0,
                $result['reason'] ?? 'non supprimable',
                $result['path'] !== null ? sprintf(' (%s)', $result['path']) : '',
            ));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Rapport : %d média(s) analysé(s), %d supprimable(s), %d supprimé(s), %d ignoré(s), espace %s.',
            $analyzed,
            $deletable,
            $deleted,
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

        return $this->mediaAssetRepository->findBy([], ['id' => 'ASC']);
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
        if (!ctype_digit($value) || $digits === '' || strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)
        ) {
            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        return (int) $value;
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
