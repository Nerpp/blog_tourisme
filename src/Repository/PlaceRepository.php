<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\Place;
use App\Entity\Tag;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Place> */
class PlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }

    /** @return list<Place> */
    public function findPublished(?Destination $destination = null, ?Category $category = null, ?Tag $tag = null, int $limit = 24): array
    {
        $qb = $this->createPublishedQueryBuilder();

        if ($destination !== null) {
            $qb
                ->andWhere('p.destination = :destination')
                ->setParameter('destination', $destination);
        }

        if ($category !== null) {
            $qb
                ->andWhere('p.category = :category')
                ->setParameter('category', $category);
        }

        if ($tag !== null) {
            $qb
                ->andWhere('tags = :tag')
                ->setParameter('tag', $tag);
        }

        return $qb
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findPublishedBySlug(string $slug): ?Place
    {
        return $this->createQueryBuilder('p')
            ->addSelect('destination', 'category', 'featuredImage', 'mediaLinks', 'mediaAssets', 'tagLinks', 'tags', 'articleLinks', 'articles')
            ->leftJoin('p.destination', 'destination')
            ->leftJoin('p.category', 'category')
            ->leftJoin('p.featuredImage', 'featuredImage')
            ->leftJoin('p.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('p.tagLinks', 'tagLinks')
            ->leftJoin('tagLinks.tag', 'tags')
            ->leftJoin('p.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('articles.id IS NULL OR articles.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('articleLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Place> */
    public function findByDestination(Destination $destination, int $limit = 12): array
    {
        return $this->findPublished(destination: $destination, limit: $limit);
    }

    /** @return list<Place> */
    public function findFeaturedPublished(int $limit): array
    {
        return $this->createPublishedQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function createPublishedQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('destination', 'category', 'featuredImage', 'tagLinks', 'tags')
            ->leftJoin('p.destination', 'destination')
            ->leftJoin('p.category', 'category')
            ->leftJoin('p.featuredImage', 'featuredImage')
            ->leftJoin('p.tagLinks', 'tagLinks')
            ->leftJoin('tagLinks.tag', 'tags')
            ->andWhere('p.status = :status')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.name', 'ASC');
    }
}
