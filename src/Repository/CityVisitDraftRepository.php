<?php

namespace App\Repository;

use App\Entity\CityVisitDraft;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
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
}
