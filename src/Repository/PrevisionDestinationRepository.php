<?php

namespace App\Repository;

use App\Entity\PrevisionDestination;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PrevisionDestination> */
final class PrevisionDestinationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrevisionDestination::class);
    }

    /** @return list<PrevisionDestination> */
    public function findForIndex(?string $query = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->orderBy('p.updatedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        $this->applySearch($queryBuilder, $query);

        /** @var list<PrevisionDestination> $destinations */
        $destinations = $queryBuilder
            ->getQuery()
            ->getResult();

        return $destinations;
    }

    /** @return list<PrevisionDestination> */
    public function findAutocompleteSuggestions(string $query, int $limit = 8): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->orderBy('p.updatedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 10)));

        $this->applySearch($queryBuilder, $query);

        /** @var list<PrevisionDestination> $destinations */
        $destinations = $queryBuilder
            ->getQuery()
            ->getResult();

        return $destinations;
    }

    private function applySearch(QueryBuilder $queryBuilder, ?string $query): void
    {
        $query = trim((string) $query);
        if (mb_strlen($query) < 2) {
            return;
        }

        $conditions = [
            'LOWER(p.title) LIKE :query',
            'LOWER(p.commune) LIKE :query',
            'LOWER(p.department) LIKE :query',
            'LOWER(p.region) LIKE :query',
            'LOWER(p.postalCode) LIKE :query',
            'LOWER(p.inseeCode) LIKE :query',
            'LOWER(p.notes) LIKE :query',
            'LOWER(p.plannedPeriod) LIKE :query',
        ];
        $queryBuilder->setParameter('query', '%'.$this->escapeLike(mb_strtolower($query)).'%');

        $matchedStatuses = $this->matchedValues($query, [
            PrevisionDestination::STATUS_IDEA => 'Idée',
            PrevisionDestination::STATUS_TO_CHECK => 'À vérifier',
            PrevisionDestination::STATUS_TO_VISIT => 'À visiter',
            PrevisionDestination::STATUS_SPOTTED => 'Repérée',
            PrevisionDestination::STATUS_ABANDONED => 'Abandonnée',
        ]);
        if ($matchedStatuses !== []) {
            $conditions[] = 'p.status IN (:matchedStatuses)';
            $queryBuilder->setParameter('matchedStatuses', $matchedStatuses);
        }

        $matchedSources = $this->matchedValues($query, [
            PrevisionDestination::SOURCE_MANUAL => 'Manuel',
            PrevisionDestination::SOURCE_SEARCH => 'Recherche',
            PrevisionDestination::SOURCE_GPS => 'GPS',
            PrevisionDestination::SOURCE_MANUAL_MAP => 'Point placé sur carte',
        ]);
        if ($matchedSources !== []) {
            $conditions[] = 'p.source IN (:matchedSources)';
            $queryBuilder->setParameter('matchedSources', $matchedSources);
        }

        $matchedPriorities = $this->matchedValues($query, [
            PrevisionDestination::PRIORITY_LOW => 'Basse',
            PrevisionDestination::PRIORITY_MEDIUM => 'Moyenne',
            PrevisionDestination::PRIORITY_HIGH => 'Haute',
        ]);
        if ($matchedPriorities !== []) {
            $conditions[] = 'p.priority IN (:matchedPriorities)';
            $queryBuilder->setParameter('matchedPriorities', $matchedPriorities);
        }

        $queryBuilder->andWhere(implode(' OR ', $conditions));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_');
    }

    /**
     * @param array<string, string> $labelsByValue
     *
     * @return list<string>
     */
    private function matchedValues(string $query, array $labelsByValue): array
    {
        $normalizedQuery = $this->normalizeSearchText($query);
        $matches = [];

        foreach ($labelsByValue as $value => $label) {
            $normalizedValue = $this->normalizeSearchText($value);
            $normalizedLabel = $this->normalizeSearchText($label);
            if (
                str_contains($normalizedValue, $normalizedQuery)
                || str_contains($normalizedLabel, $normalizedQuery)
            ) {
                $matches[] = $value;
            }
        }

        return $matches;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ç' => 'c',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
        ]);
    }
}
