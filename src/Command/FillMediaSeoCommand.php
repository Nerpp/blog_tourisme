<?php

namespace App\Command;

use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Repository\MediaAssetRepository;
use App\Service\Media\MediaSeoTextService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:seo-fill',
    description: 'Remplit les titres et textes alternatifs SEO des médias existants sans renommer les fichiers.',
)]
final class FillMediaSeoCommand extends Command
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly MediaSeoTextService $mediaSeoTextService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans écrire en base.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Applique les changements.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Limite à un MediaAsset.')
            ->addOption('hike-id', null, InputOption::VALUE_REQUIRED, 'Limite aux médias d’une randonnée.')
            ->addOption('city-visit-id', null, InputOption::VALUE_REQUIRED, 'Limite aux médias d’une visite de ville.')
            ->addOption('place-id', null, InputOption::VALUE_REQUIRED, 'Limite aux médias d’un lieu.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run') || !(bool) $input->getOption('force');
        try {
            $mediaId = $this->positiveIntOption($input->getOption('id'), '--id');
            $hikeId = $this->positiveIntOption($input->getOption('hike-id'), '--hike-id');
            $cityVisitId = $this->positiveIntOption($input->getOption('city-visit-id'), '--city-visit-id');
            $placeId = $this->positiveIntOption($input->getOption('place-id'), '--place-id');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $medias = $mediaId !== null
            ? array_filter([$this->mediaAssetRepository->find($mediaId)])
            : $this->mediaAssetRepository->findAll();

        $analysed = 0;
        $updated = 0;
        $ignored = 0;
        $errors = 0;

        foreach ($medias as $media) {
            try {
                $context = $this->resolveContext($media, $hikeId, $cityVisitId, $placeId);
                if (!$context instanceof HikeDraft && !$context instanceof CityVisitDraft && !$context instanceof Place) {
                    ++$ignored;
                    continue;
                }

                ++$analysed;
                $newTitle = $this->mediaSeoTextService->titleForContext($context, $media->getMediaType(), $media->getImageType());
                $newAltText = $this->mediaSeoTextService->altTextForContext($context, $media->getMediaType(), $media->getImageType());
                $titleNeedsUpdate = $this->mediaSeoTextService->isTechnicalText($media->getTitle(), $media);
                $altNeedsUpdate = $this->mediaSeoTextService->isTechnicalText($media->getAltText(), $media);

                if (!$titleNeedsUpdate && !$altNeedsUpdate) {
                    ++$ignored;
                    continue;
                }

                $io->writeln(sprintf(
                    'MediaAsset #%d%s',
                    $media->getId(),
                    $dryRun ? ' serait mis à jour' : ' mis à jour',
                ));

                if ($titleNeedsUpdate) {
                    $io->writeln(sprintf('  title: %s => %s', $this->displayValue($media->getTitle()), $newTitle));
                    if (!$dryRun) {
                        $media->setTitle($newTitle);
                    }
                }

                if ($altNeedsUpdate) {
                    $io->writeln(sprintf('  altText: %s => %s', $this->displayValue($media->getAltText()), $newAltText));
                    if (!$dryRun) {
                        $media->setAltText($newAltText);
                    }
                }

                ++$updated;
            } catch (\Throwable $exception) {
                ++$errors;
                $io->warning(sprintf('MediaAsset #%d : %s', $media->getId(), $exception->getMessage()));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Analysés', $dryRun ? 'À mettre à jour' : 'Mis à jour', 'Ignorés', 'Erreurs'], [[
            $analysed,
            $updated,
            $ignored,
            $errors,
        ]]);

        if ($dryRun) {
            $io->note('Dry-run actif : aucune donnée n’a été modifiée. Ajoutez --force pour appliquer.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveContext(MediaAsset $media, ?int $hikeId, ?int $cityVisitId, ?int $placeId): HikeDraft|CityVisitDraft|Place|null
    {
        $hasScopedFilter = $hikeId !== null || $cityVisitId !== null || $placeId !== null;

        foreach ($media->getHikeDraftLinks() as $link) {
            $hike = $link->getHikeDraft();
            if ($hike instanceof HikeDraft && (($hasScopedFilter && $hikeId !== null && $hike->getId() === $hikeId) || !$hasScopedFilter)) {
                return $hike;
            }
        }

        foreach ($media->getHikePointLinks() as $link) {
            $hike = $link->getHikePoint()?->getHikeDraft();
            if ($hike instanceof HikeDraft && (($hasScopedFilter && $hikeId !== null && $hike->getId() === $hikeId) || !$hasScopedFilter)) {
                return $hike;
            }
        }

        foreach ($media->getCityVisitDraftLinks() as $link) {
            $cityVisit = $link->getCityVisitDraft();
            if ($cityVisit instanceof CityVisitDraft && (($hasScopedFilter && $cityVisitId !== null && $cityVisit->getId() === $cityVisitId) || !$hasScopedFilter)) {
                return $cityVisit;
            }
        }

        foreach ($media->getCityVisitPointLinks() as $link) {
            $cityVisit = $link->getCityVisitPoint()?->getCityVisitDraft();
            if ($cityVisit instanceof CityVisitDraft && (($hasScopedFilter && $cityVisitId !== null && $cityVisit->getId() === $cityVisitId) || !$hasScopedFilter)) {
                return $cityVisit;
            }
        }

        foreach ($media->getPlaceLinks() as $link) {
            $place = $link->getPlace();
            if ($place instanceof Place && (($hasScopedFilter && $placeId !== null && $place->getId() === $placeId) || !$hasScopedFilter)) {
                return $place;
            }
        }

        return null;
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

    private function displayValue(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '[vide]' : $value;
    }
}
