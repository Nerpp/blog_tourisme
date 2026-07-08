<?php

namespace App\Command;

use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\MediaAssetRepository;
use App\Service\Media\VideoThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:generate-video-thumbnails',
    description: 'Generate missing thumbnails for local videos and supported external videos.',
)]
final class GenerateVideoThumbnailsCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly VideoThumbnailGenerator $videoThumbnailGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate thumbnails even when a thumbnail path already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated without writing database changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $mediaAssets = $this->mediaAssetRepository->findBy(['mediaType' => MediaType::Video]);

        $generated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($mediaAssets as $media) {
            if (!$force && $media->getThumbnailPath() !== null && $media->getThumbnailPath() !== '') {
                ++$skipped;
                $io->text(sprintf('#%d ignorée : miniature déjà présente.', $media->getId()));

                continue;
            }

            if ($dryRun) {
                ++$generated;
                $io->text(sprintf('#%d serait traitée : %s', $media->getId(), $media->getFilePath() ?? $media->getExternalUrl() ?? 'vidéo'));

                continue;
            }

            $thumbnailPath = $this->videoThumbnailGenerator->generateForMedia($media, $force);
            if ($thumbnailPath !== null) {
                ++$generated;
                $io->text(sprintf('#%d miniature générée : %s', $media->getId(), $thumbnailPath));

                continue;
            }

            if (!$this->canAttemptThumbnailGeneration($media->getFilePath(), $media->getVideoType(), $media->getExternalUrl())) {
                ++$skipped;
                $io->text(sprintf('#%d ignorée : aucune source locale ou thumbnail externe supportée.', $media->getId()));

                continue;
            }

            ++$errors;
            $io->warning(sprintf('#%d erreur : miniature non générée.', $media->getId()));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Miniatures vidéo : %d générée%s, %d ignorée%s, %d erreur%s.',
            $generated,
            $generated > 1 ? 's' : '',
            $skipped,
            $skipped > 1 ? 's' : '',
            $errors,
            $errors > 1 ? 's' : '',
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function canAttemptThumbnailGeneration(?string $filePath, ?VideoType $videoType, ?string $externalUrl): bool
    {
        if ($filePath !== null && $filePath !== '' && !str_starts_with($filePath, 'http://') && !str_starts_with($filePath, 'https://')) {
            return true;
        }

        return $videoType === VideoType::Youtube && $externalUrl !== null && $externalUrl !== '';
    }
}
