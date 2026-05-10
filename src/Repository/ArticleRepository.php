<?php

namespace App\Repository;

use App\Entity\Article;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Article> */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /** @return list<Article> */
    public function findPublished(int $limit = 24): array
    {
        return $this->createPublishedQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findPublishedBySlug(string $slug): ?Article
    {
        return $this->createQueryBuilder('a')
            ->addSelect('category', 'featuredImage', 'destinationLinks', 'destinations', 'placeLinks', 'places', 'mediaLinks', 'mediaAssets', 'tagLinks', 'tags')
            ->leftJoin('a.category', 'category')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.destinationLinks', 'destinationLinks')
            ->leftJoin('destinationLinks.destination', 'destinations')
            ->leftJoin('a.placeLinks', 'placeLinks')
            ->leftJoin('placeLinks.place', 'places')
            ->leftJoin('a.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('a.tagLinks', 'tagLinks')
            ->leftJoin('tagLinks.tag', 'tags')
            ->andWhere('a.slug = :slug')
            ->andWhere('a.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('destinationLinks.position', 'ASC')
            ->addOrderBy('placeLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Article> */
    public function findLatestPublished(int $limit): array
    {
        return $this->createPublishedQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function createPublishedQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect('category', 'featuredImage', 'destinationLinks', 'destinations', 'mediaLinks', 'mediaAssets', 'tagLinks', 'tags')
            ->leftJoin('a.category', 'category')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.destinationLinks', 'destinationLinks')
            ->leftJoin('destinationLinks.destination', 'destinations')
            ->leftJoin('a.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('a.tagLinks', 'tagLinks')
            ->leftJoin('tagLinks.tag', 'tags')
            ->andWhere('a.status = :status')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('a.id', 'DESC');
    }
}
