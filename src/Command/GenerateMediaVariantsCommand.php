<?php

namespace App\Command;

use App\Entity\MediaAsset;
use App\Repository\MediaAssetRepository;
use App\Service\Media\MediaVariantService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:generate-variants',
    description: 'Generate responsive image variants and video poster variants for local media assets.',
)]
final class GenerateMediaVariantsCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly MediaVariantService $mediaVariantService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate variants even when variants already exist.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Restrict generation to one media asset id.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be generated without writing files or database changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        try {
            $id = $this->positiveIntOption($input->getOption('id'), '--id');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if (!$this->variantsColumnExists()) {
            $io->error('La colonne media_asset.variants est absente. Lancez d’abord la migration Doctrine générée, puis relancez cette commande.');

            return Command::FAILURE;
        }

        $mediaAssets = $this->resolveMediaAssets($id);
        if ($mediaAssets === []) {
            $io->warning('Aucun média trouvé.');

            return Command::SUCCESS;
        }

        $io->title('Génération des variantes média');
        $io->listing([
            'WebP: '.($this->mediaVariantService->supportsWebp() ? 'supporté' : 'non supporté'),
            'AVIF: '.($this->mediaVariantService->supportsAvif() ? 'supporté' : 'non supporté'),
            'Photos standards: '.implode(', ', $this->mediaVariantService->standardOutputFormats()).' uniquement (socle 600/960/1600/1920 px, affichages 320/480/640/768/960 px)',
            'Médias spécialisés et posters: '.implode(', ', $this->mediaVariantService->supportedOutputFormats()).' (640, 960, 1280, 2560 px)',
        ]);

        $generated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($mediaAssets as $media) {
            if (!$this->mediaVariantService->supports($media)) {
                ++$skipped;
                $io->text(sprintf('#%d ignoré : média externe ou type non supporté.', $media->getId()));

                continue;
            }

            if (!$force && $this->mediaVariantService->hasUsableVariants($media)) {
                ++$skipped;
                $io->text(sprintf('#%d ignoré : variantes déjà présentes.', $media->getId()));

                continue;
            }

            if ($dryRun) {
                ++$generated;
                $io->text(sprintf('#%d serait traité : %s', $media->getId(), $media->getFilePath() ?? $media->getThumbnailPath() ?? 'poster'));

                continue;
            }

            $result = $this->mediaVariantService->generateForMedia($media, $force);
            if ($result['generated']) {
                ++$generated;
                $io->text(sprintf('#%d variantes générées.', $media->getId()));

                continue;
            }

            if ($result['status'] === 'error') {
                ++$errors;
                $io->warning(sprintf('#%d erreur : %s', $media->getId(), $result['message'] ?? 'erreur inconnue'));

                continue;
            }

            ++$skipped;
            $io->text(sprintf('#%d ignoré : %s', $media->getId(), $result['message'] ?? 'non traité'));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Rapport : %d généré(s), %d ignoré(s), %d erreur(s).',
            $generated,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<MediaAsset> */
    private function resolveMediaAssets(?int $id): array
    {
        if ($id !== null) {
            $media = $this->mediaAssetRepository->find($id);

            return $media instanceof MediaAsset ? [$media] : [];
        }

        return array_values($this->mediaAssetRepository->findBy([], ['id' => 'ASC']));
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

    private function variantsColumnExists(): bool
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist(['media_asset'])) {
            return false;
        }

        return $schemaManager->introspectTable('media_asset')->hasColumn('variants');
    }
}
