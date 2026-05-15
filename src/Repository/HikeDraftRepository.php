<?php

namespace App\Repository;

use App\Entity\HikeDraft;
use App\Entity\User;
use App\Enum\HikeDraftStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HikeDraft> */
class HikeDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HikeDraft::class);
    }

    public function findCurrentDraftForUser(User $user): ?HikeDraft
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.createdBy = :user')
            ->andWhere('h.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', HikeDraftStatus::Draft)
            ->orderBy('h.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestFinishedForHomepage(): ?HikeDraft
    {
        $latestHikeRow = $this->createQueryBuilder('h')
            ->select('h.id')
            ->andWhere('h.status = :status')
            ->andWhere('h.finishedAt IS NOT NULL')
            ->setParameter('status', HikeDraftStatus::Finished)
            ->orderBy('h.finishedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestHikeRow === null) {
            return null;
        }

        return $this->createQueryBuilder('h')
            ->addSelect('mediaLinks', 'mediaAssets')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('h.id = :id')
            ->setParameter('id', $latestHikeRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPublicBySlug(string $slug): ?HikeDraft
    {
        return $this->createQueryBuilder('h')
            ->addSelect('destination', 'points', 'mediaLinks', 'mediaAssets')
            ->leftJoin('h.destination', 'destination')
            ->leftJoin('h.points', 'points')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('h.slug = :slug')
            ->andWhere('h.status IN (:statuses)')
            ->setParameter('slug', $slug)
            ->setParameter('statuses', [
                HikeDraftStatus::Finished,
                HikeDraftStatus::Converted,
            ])
            ->orderBy('points.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
