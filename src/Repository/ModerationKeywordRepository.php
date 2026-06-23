<?php

namespace App\Repository;

use App\Entity\ModerationKeyword;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ModerationKeyword> */
class ModerationKeywordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationKeyword::class);
    }

    /** @return list<ModerationKeyword> */
    public function findEnabledKeywords(): array
    {
        /** @var list<ModerationKeyword> $keywords */
        $keywords = $this->createQueryBuilder('k')
            ->andWhere('k.enabled = true')
            ->orderBy('k.type', 'DESC')
            ->addOrderBy('k.keyword', 'ASC')
            ->getQuery()
            ->getResult();

        return $keywords;
    }
}
