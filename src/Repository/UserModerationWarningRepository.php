<?php

namespace App\Repository;

use App\Entity\UserModerationWarning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserModerationWarning> */
class UserModerationWarningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserModerationWarning::class);
    }
}
