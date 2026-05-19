<?php

namespace App\Repository;

use App\Entity\CityVisitDraft;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CityVisitDraft> */
class CityVisitDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CityVisitDraft::class);
    }

    public function findCurrentDraftForUser(User $user): ?CityVisitDraft
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.createdBy = :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', CityVisitDraftStatus::Draft)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestFinishedForHomepage(): ?CityVisitDraft
    {
        $latestCityVisitRow = $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.status = :status')
            ->andWhere('c.finishedAt IS NOT NULL')
            ->setParameter('status', CityVisitDraftStatus::Finished)
            ->orderBy('c.finishedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestCityVisitRow === null) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->addSelect('mediaLinks', 'mediaAssets')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('c.id = :id')
            ->setParameter('id', $latestCityVisitRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPublicBySlug(string $slug): ?CityVisitDraft
    {
        return $this->createQueryBuilder('c')
            ->addSelect('destination', 'destinationParent', 'destinationGrandParent', 'destinationGreatGrandParent', 'points', 'mediaLinks', 'mediaAssets')
            ->leftJoin('c.destination', 'destination')
            ->leftJoin('destination.parent', 'destinationParent')
            ->leftJoin('destinationParent.parent', 'destinationGrandParent')
            ->leftJoin('destinationGrandParent.parent', 'destinationGreatGrandParent')
            ->leftJoin('c.points', 'points')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('c.slug = :slug')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('slug', $slug)
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished,
                CityVisitDraftStatus::Converted,
            ])
            ->orderBy('points.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<CityVisitDraft>
     */
    public function findPublicByDestinationIds(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->addSelect('destination', 'mediaLinks', 'mediaAssets')
            ->leftJoin('c.destination', 'destination')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('destination.id IN (:destinationIds)')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('destinationIds', $destinationIds, ArrayParameterType::INTEGER)
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished->value,
                CityVisitDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->orderBy('c.finishedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
