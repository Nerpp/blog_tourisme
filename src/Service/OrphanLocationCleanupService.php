<?php

namespace App\Service;

use App\Entity\ArticleDestination;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\Place;
use Doctrine\ORM\EntityManagerInterface;

final class OrphanLocationCleanupService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     status: 'ignored'|'deleted'|'kept',
     *     reason: string,
     *     usageCount: int,
     *     childrenCount: int
     * }
     */
    public function cleanupDestinationIfOrphan(?Destination $destination): array
    {
        if (!$destination instanceof Destination || $destination->getId() === null) {
            return [
                'status' => 'ignored',
                'reason' => 'null',
                'usageCount' => 0,
                'childrenCount' => 0,
            ];
        }

        $childrenCount = $this->countChildren($destination);
        if ($childrenCount > 0) {
            return [
                'status' => 'kept',
                'reason' => 'children',
                'usageCount' => $this->countUsages($destination),
                'childrenCount' => $childrenCount,
            ];
        }

        $usageCount = $this->countUsages($destination);
        if ($usageCount > 0) {
            return [
                'status' => 'kept',
                'reason' => 'used',
                'usageCount' => $usageCount,
                'childrenCount' => 0,
            ];
        }

        $this->entityManager->remove($destination);

        return [
            'status' => 'deleted',
            'reason' => 'orphan',
            'usageCount' => 0,
            'childrenCount' => 0,
        ];
    }

    private function countChildren(Destination $destination): int
    {
        return $this->countBy(Destination::class, 'e.parent = :destination', $destination);
    }

    private function countUsages(Destination $destination): int
    {
        return $this->countBy(HikeDraft::class, 'e.destination = :destination OR e.geographicDestination = :destination', $destination)
            + $this->countBy(CityVisitDraft::class, 'e.destination = :destination OR e.geographicDestination = :destination', $destination)
            + $this->countBy(Place::class, 'e.destination = :destination', $destination)
            + $this->countBy(ArticleDestination::class, 'e.destination = :destination', $destination);
    }

    /** @param class-string $entityClass */
    private function countBy(string $entityClass, string $where, Destination $destination): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->andWhere($where)
            ->setParameter('destination', $destination)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
