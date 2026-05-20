<?php

namespace App\Repository;

use App\Entity\Category;
use App\Enum\CategoryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return list<Category> */
    public function findArticleCategories(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.type IN (:types)')
            ->setParameter('types', [CategoryType::Article, CategoryType::Both])
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
