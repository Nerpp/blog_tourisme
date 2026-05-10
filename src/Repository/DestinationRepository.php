<?php

namespace App\Repository;

use App\Entity\Destination;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Destination> */
class DestinationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Destination::class);
    }

    /** @return list<Destination> */
    public function findRootDestinations(): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('children', 'grandchildren', 'greatGrandchildren')
            ->leftJoin('d.children', 'children')
            ->leftJoin('children.children', 'grandchildren')
            ->leftJoin('grandchildren.children', 'greatGrandchildren')
            ->andWhere('d.parent IS NULL')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('children.name', 'ASC')
            ->addOrderBy('grandchildren.name', 'ASC')
            ->addOrderBy('greatGrandchildren.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?Destination
    {
        return $this->createQueryBuilder('d')
            ->addSelect('parent', 'children', 'places', 'articleLinks', 'articles')
            ->leftJoin('d.parent', 'parent')
            ->leftJoin('d.children', 'children')
            ->leftJoin('d.places', 'places', 'WITH', 'places.status = :published')
            ->leftJoin('d.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles', 'WITH', 'articles.status = :published')
            ->andWhere('d.slug = :slug')
            ->setParameter('slug', $slug)
            ->setParameter('published', ContentStatus::Published)
            ->orderBy('children.name', 'ASC')
            ->addOrderBy('places.publishedAt', 'DESC')
            ->addOrderBy('articleLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Destination> */
    public function findChildren(Destination $destination): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.parent = :destination')
            ->setParameter('destination', $destination)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Destination> */
    public function findDiscoverableDestinations(int $limit = 6): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('parent')
            ->leftJoin('d.parent', 'parent')
            ->andWhere('d.parent IS NOT NULL')
            ->orderBy('d.type', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
