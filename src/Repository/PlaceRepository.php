<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\Place;
use App\Entity\Tag;
use App\Enum\ContentStatus;
use App\Enum\MediaType;
use Doctrine\DBAL\ArrayParameterType;
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
    public function findPublishedForSitemap(): array
    {
        /** @var list<Place> $places */
        $places = $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('p.slug IS NOT NULL')
            ->andWhere('p.slug != :emptySlug')
            ->setParameter('status', ContentStatus::Published)
            ->setParameter('emptySlug', '')
            ->orderBy('p.slug', 'ASC')
            ->getQuery()
            ->getResult();

        return $places;
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

        /** @var list<Place> $places */
        $places = $qb
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $places;
    }

    public function findPublishedBySlug(string $slug): ?Place
    {
        /** @var Place|null $place */
        $place = $this->createQueryBuilder('p')
            ->addSelect('destination', 'category', 'featuredImage', 'mediaLinks', 'mediaAssets', 'tagLinks', 'tags', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('p.destination', 'destination')
            ->leftJoin('p.category', 'category')
            ->leftJoin('p.featuredImage', 'featuredImage')
            ->leftJoin('p.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('p.tagLinks', 'tagLinks')
            ->leftJoin('tagLinks.tag', 'tags')
            ->leftJoin('p.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('articles.id IS NULL OR articles.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('articleLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $place;
    }

    /** @return list<Place> */
    public function findByDestination(Destination $destination, int $limit = 12): array
    {
        return $this->findPublished(destination: $destination, limit: $limit);
    }

    public function findLatestPublishedWithMediaByDestination(Destination $destination): ?Place
    {
        /** @var array{id: int}|null $latestPlaceRow */
        $latestPlaceRow = $this->createQueryBuilder('p')
            ->select('p.id')
            ->leftJoin('p.featuredImage', 'featuredImage')
            ->leftJoin('p.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('p.destination = :destination')
            ->andWhere('p.status = :status')
            ->andWhere('featuredImage.mediaType = :mediaType OR mediaAssets.mediaType = :mediaType')
            ->setParameter('destination', $destination)
            ->setParameter('status', ContentStatus::Published)
            ->setParameter('mediaType', MediaType::Image)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestPlaceRow === null) {
            return null;
        }

        /** @var Place|null $place */
        $place = $this->createQueryBuilder('p')
            ->addSelect('featuredImage', 'mediaLinks', 'mediaAssets')
            ->leftJoin('p.featuredImage', 'featuredImage')
            ->leftJoin('p.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('p.id = :id')
            ->setParameter('id', $latestPlaceRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $place;
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<Place>
     */
    public function findPublishedByDestinationIds(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        /** @var list<Place> $places */
        $places = $this->createPublishedQueryBuilder()
            ->andWhere('destination.id IN (:destinationIds)')
            ->setParameter('destinationIds', $destinationIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return $places;
    }

    /** @return list<Place> */
    public function findFeaturedPublished(int $limit): array
    {
        /** @var list<Place> $places */
        $places = $this->createPublishedQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $places;
    }

    private function createPublishedQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('destination', 'category', 'featuredImage', 'mediaLinks', 'mediaAssets', 'tagLinks', 'tags')
            ->leftJoin('p.destination', 'destination')
            ->leftJoin('p.category', 'category')
            ->leftJoin('p.featuredImage', 'featuredImage')
            ->leftJoin('p.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('p.tagLinks', 'tagLinks')
            ->leftJoin('tagLinks.tag', 'tags')
            ->andWhere('p.status = :status')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('p.name', 'ASC');
    }
}
