<?php

namespace App\Command;

use App\Entity\MediaAsset;
use App\Repository\MediaAssetRepository;
use App\Service\Media\MediaDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:cleanup-orphans',
    description: 'List or delete media assets that are no longer used by any content.',
)]
final class CleanupOrphanMediaCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly MediaDeletionService $mediaDeletionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show orphan media without deleting anything.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete orphan media and their local upload files.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Restrict cleanup to one media asset id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if (!$dryRun && !$force) {
            $io->error('Utilisez --dry-run pour auditer ou --force pour supprimer les médias orphelins.');

            return Command::INVALID;
        }

        $mediaAssets = $this->resolveMediaAssets($input->getOption('id'));
        $orphans = 0;
        $skipped = 0;
        $deleted = 0;

        foreach ($mediaAssets as $media) {
            $usage = $this->mediaDeletionService->usage($media);
            if (!$usage['orphan']) {
                ++$skipped;
                $io->text(sprintf('#%d conservé : encore utilisé %d fois.', $media->getId(), $usage['usageCount']));

                continue;
            }

            ++$orphans;
            $result = $this->mediaDeletionService->deleteIfOrphan($media, $dryRun);
            if ($result['deleted']) {
                ++$deleted;
            }

            $io->section(sprintf('#%d orphelin%s', $media->getId(), $dryRun ? ' (dry-run)' : ''));
            if ($result['files'] === []) {
                $io->text('Aucun fichier local supprimable.');
            } else {
                $io->listing($result['files']);
            }
        }

        if ($force) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Rapport : %d orphelin(s), %d supprimé(s), %d conservé(s).',
            $orphans,
            $deleted,
            $skipped,
        ));

        return Command::SUCCESS;
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
}
