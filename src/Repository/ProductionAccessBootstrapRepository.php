<?php

namespace App\Repository;

use App\Entity\ProductionAccessBootstrap;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionAccessBootstrap> */
class ProductionAccessBootstrapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionAccessBootstrap::class);
    }

    public function findCompleted(): ?ProductionAccessBootstrap
    {
        return $this->findOneBy([], ['completedAt' => 'ASC']);
    }
}
