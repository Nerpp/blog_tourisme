<?php

namespace App\Service\RouteStep;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/**
 * Owns every multi-row mutation of route-step positions.
 *
 * Coordinates, editorial data and media relations are deliberately never read
 * or changed here.
 */
final class RouteStepOrderingService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<HikePoint|CityVisitPoint> */
    public function orderedSteps(HikeDraft|CityVisitDraft $draft): array
    {
        $steps = $draft->getPoints()->toArray();
        usort(
            $steps,
            static fn (HikePoint|CityVisitPoint $first, HikePoint|CityVisitPoint $second): int => $first->getPosition() <=> $second->getPosition(),
        );

        return $steps;
    }

    public function insertAtPosition(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
        int $position,
    ): void {
        $this->assertCompatibleTypes($draft, $step);
        $this->assertInsertableOwnership($draft, $step);

        $this->transactional($draft, function () use ($draft, $step, $position): void {
            $steps = $this->orderedSteps($draft);
            if ($this->containsIdenticalStep($steps, $step)) {
                throw new RouteStepOrderingException('Cette étape appartient déjà au parcours.');
            }

            if ($position < 1 || $position > count($steps) + 1) {
                throw new RouteStepOrderingException('La position d’insertion est invalide.');
            }

            $this->attachStep($draft, $step);
            array_splice($steps, $position - 1, 0, [$step]);
            $this->writeCanonicalPositions($steps);
        });
    }

    public function moveToPosition(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
        int $position,
    ): void {
        $this->transactional($draft, function () use ($draft, $step, $position): void {
            $this->assertOwnedStep($draft, $step);
            $steps = $this->orderedSteps($draft);
            if ($position < 1 || $position > count($steps)) {
                throw new RouteStepOrderingException('La position de destination est invalide.');
            }

            $steps = array_values(array_filter(
                $steps,
                static fn (HikePoint|CityVisitPoint $candidate): bool => $candidate !== $step,
            ));
            array_splice($steps, $position - 1, 0, [$step]);
            $this->writeCanonicalPositions($steps);
        });
    }

    /**
     * @param list<mixed> $orderedStepIds
     *
     * @return list<HikePoint|CityVisitPoint>
     */
    public function reorder(HikeDraft|CityVisitDraft $draft, array $orderedStepIds): array
    {
        foreach ($orderedStepIds as $stepId) {
            if (!is_int($stepId) || $stepId < 1) {
                throw new RouteStepOrderingException('Tous les identifiants d’étape doivent être des entiers positifs.');
            }
        }

        if (count($orderedStepIds) !== count(array_unique($orderedStepIds))) {
            throw new RouteStepOrderingException('La liste des étapes contient un doublon.');
        }

        return $this->transactional($draft, function () use ($draft, $orderedStepIds): array {
            $steps = $this->orderedSteps($draft);
            if (count($orderedStepIds) !== count($steps)) {
                throw new RouteStepOrderingException('La liste doit contenir exactement toutes les étapes du parcours.');
            }

            $stepsById = [];
            foreach ($steps as $step) {
                $id = $step->getId();
                if ($id === null) {
                    throw new RouteStepOrderingException('Une étape non enregistrée empêche la réorganisation.');
                }
                $stepsById[$id] = $step;
            }

            $orderedSteps = [];
            foreach ($orderedStepIds as $stepId) {
                $step = $stepsById[$stepId] ?? null;
                if (!$step instanceof HikePoint && !$step instanceof CityVisitPoint) {
                    throw new RouteStepOrderingException('Une étape demandée n’appartient pas à ce parcours.');
                }
                $orderedSteps[] = $step;
            }

            $this->writeCanonicalPositions($orderedSteps);

            return $orderedSteps;
        });
    }

    public function removeAndCompact(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
    ): void {
        $this->transactional($draft, function () use ($draft, $step): void {
            $this->assertOwnedStep($draft, $step);
            $remainingSteps = array_values(array_filter(
                $this->orderedSteps($draft),
                static fn (HikePoint|CityVisitPoint $candidate): bool => $candidate !== $step,
            ));
            $this->writeTemporaryPositions($remainingSteps);
            $this->detachStep($draft, $step);
            $this->entityManager->remove($step);
            $this->entityManager->flush();
            $this->writeFinalPositions($remainingSteps);
            $this->entityManager->flush();
        });
    }

    public function normalizePositions(HikeDraft|CityVisitDraft $draft): void
    {
        $this->transactional($draft, function () use ($draft): void {
            $steps = $this->orderedSteps($draft);
            $this->writeCanonicalPositions($steps);
        });
    }

    /** @param list<HikePoint|CityVisitPoint> $steps */
    private function writeCanonicalPositions(array $steps): void
    {
        $this->writeTemporaryPositions($steps);
        foreach ($steps as $step) {
            $this->entityManager->persist($step);
        }
        $this->entityManager->flush();
        $this->writeFinalPositions($steps);
        $this->entityManager->flush();
    }

    /** @param list<HikePoint|CityVisitPoint> $steps */
    private function writeTemporaryPositions(array $steps): void
    {
        $largestAbsolutePosition = 0;
        foreach ($steps as $step) {
            $largestAbsolutePosition = max($largestAbsolutePosition, abs($step->getPosition()));
        }

        $temporaryPosition = -($largestAbsolutePosition + count($steps) + 1);
        foreach ($steps as $step) {
            $step->setPosition($temporaryPosition--);
        }
    }

    /** @param list<HikePoint|CityVisitPoint> $steps */
    private function writeFinalPositions(array $steps): void
    {
        foreach ($steps as $index => $step) {
            $step->setPosition($index + 1);
        }
    }

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    private function transactional(HikeDraft|CityVisitDraft $draft, callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($draft, $operation): mixed {
            if ($draft->getId() !== null) {
                $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            }

            return $operation();
        });
    }

    private function assertCompatibleTypes(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
    ): void {
        if (($draft instanceof HikeDraft && !$step instanceof HikePoint)
            || ($draft instanceof CityVisitDraft && !$step instanceof CityVisitPoint)) {
            throw new RouteStepOrderingException('Le type de l’étape ne correspond pas au parcours.');
        }
    }

    private function assertInsertableOwnership(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
    ): void {
        if ($step instanceof HikePoint) {
            $owner = $step->getHikeDraft();
        } else {
            $owner = $step->getCityVisitDraft();
        }

        if ($owner !== null && $owner !== $draft) {
            throw new RouteStepOrderingException('Cette étape appartient à un autre parcours.');
        }
    }

    private function assertOwnedStep(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
    ): void {
        $this->assertCompatibleTypes($draft, $step);

        $owner = $step instanceof HikePoint ? $step->getHikeDraft() : $step->getCityVisitDraft();
        if ($owner !== $draft || !$draft->getPoints()->contains($step)) {
            throw new RouteStepOrderingException('Cette étape n’appartient pas à ce parcours.');
        }
    }

    /** @param list<HikePoint|CityVisitPoint> $steps */
    private function containsIdenticalStep(array $steps, HikePoint|CityVisitPoint $step): bool
    {
        foreach ($steps as $candidate) {
            if ($candidate === $step) {
                return true;
            }
        }

        return false;
    }

    private function attachStep(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
    ): void {
        if ($draft instanceof HikeDraft && $step instanceof HikePoint) {
            $draft->addPoint($step);
        } elseif ($draft instanceof CityVisitDraft && $step instanceof CityVisitPoint) {
            $draft->addPoint($step);
        }
    }

    private function detachStep(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $step,
    ): void {
        if ($draft instanceof HikeDraft && $step instanceof HikePoint) {
            $draft->removePoint($step);
        } elseif ($draft instanceof CityVisitDraft && $step instanceof CityVisitPoint) {
            $draft->removePoint($step);
        }
    }
}
