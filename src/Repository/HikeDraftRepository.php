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
}
