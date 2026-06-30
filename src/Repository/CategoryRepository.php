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
        /** @var list<Category> $categories */
        $categories = $this->createQueryBuilder('c')
            ->andWhere('c.type IN (:types)')
            ->setParameter('types', [CategoryType::Article, CategoryType::Both])
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    /** @return list<Category> */
    public function findUsedForPublicArticles(): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->select('DISTINCT c')
            ->innerJoin('c.articles', 'a');
        ArticleRepository::restrictToPubliclyVisible($queryBuilder, 'a');

        /** @var list<Category> $categories */
        $categories = $queryBuilder
            ->andWhere('c.type IN (:types)')
            ->setParameter('types', [CategoryType::Article, CategoryType::Both])
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    public function findOneArticleCategoryById(int $id): ?Category
    {
        /** @var Category|null $category */
        $category = $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.type IN (:types)')
            ->setParameter('id', $id)
            ->setParameter('types', [CategoryType::Article, CategoryType::Both])
            ->getQuery()
            ->getOneOrNullResult();

        return $category;
    }
}
