<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Destination;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
use App\Enum\MediaType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
        return $this->findPublishedForListing(null, $limit);
    }

    /** @return list<Article> */
    public function findPublishedForListing(?string $query = null, int $limit = 24): array
    {
        /** @var list<Article> $articles */
        $articles = $this->applySearch($this->createPublishedQueryBuilder(), $query)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $articles;
    }

    /** @return list<Article> */
    public function findPublishedSuggestions(string $query, int $limit = 8): array
    {
        /** @var list<Article> $articles */
        $articles = $this->applySearch(
            $this->createQueryBuilder('a')
                ->addSelect('category')
                ->leftJoin('a.category', 'category')
                ->andWhere('a.status = :status')
                ->setParameter('status', ContentStatus::Published)
                ->orderBy('a.publishedAt', 'DESC')
                ->addOrderBy('a.id', 'DESC'),
            $query,
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $articles;
    }

    public function findPublishedBySlug(string $slug): ?Article
    {
        /** @var Article|null $article */
        $article = $this->createQueryBuilder('a')
            ->addSelect('category', 'featuredImage', 'destinationLinks', 'destinations', 'placeLinks', 'places', 'hikeLinks', 'hikes', 'cityVisitLinks', 'cityVisits', 'mediaLinks', 'mediaAssets', 'tagLinks', 'tags')
            ->leftJoin('a.category', 'category')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.destinationLinks', 'destinationLinks')
            ->leftJoin('destinationLinks.destination', 'destinations')
            ->leftJoin('a.placeLinks', 'placeLinks')
            ->leftJoin('placeLinks.place', 'places')
            ->leftJoin('a.hikeLinks', 'hikeLinks')
            ->leftJoin('hikeLinks.hikeDraft', 'hikes')
            ->leftJoin('a.cityVisitLinks', 'cityVisitLinks')
            ->leftJoin('cityVisitLinks.cityVisitDraft', 'cityVisits')
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
            ->addOrderBy('hikeLinks.position', 'ASC')
            ->addOrderBy('cityVisitLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $article;
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<Article>
     */
    public function findPublishedByDestinationIds(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        /** @var list<Article> $articles */
        $articles = $this->createPublishedQueryBuilder()
            ->andWhere('(destinations.id IN (:destinationIds) OR (hikeDestinations.id IN (:destinationIds) AND hikes.status IN (:hikeStatuses)) OR (cityVisitDestinations.id IN (:destinationIds) AND cityVisits.status IN (:cityVisitStatuses)))')
            ->setParameter('destinationIds', $destinationIds, ArrayParameterType::INTEGER)
            ->setParameter('hikeStatuses', [
                HikeDraftStatus::Finished->value,
                HikeDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->setParameter('cityVisitStatuses', [
                CityVisitDraftStatus::Finished->value,
                CityVisitDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        return $articles;
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<Article>
     */
    public function findPublishedDirectlyByDestinationIds(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        /** @var list<Article> $articles */
        $articles = $this->createPublishedQueryBuilder()
            ->andWhere('destinations.id IN (:destinationIds)')
            ->setParameter('destinationIds', $destinationIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return $articles;
    }

    private function createPublishedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect(
                'category',
                'featuredImage',
                'destinationLinks',
                'destinations',
                'placeLinks',
                'places',
                'hikeLinks',
                'hikes',
                'hikeDestinations',
                'cityVisitLinks',
                'cityVisits',
                'cityVisitDestinations',
                'mediaLinks',
                'mediaAssets',
                'tagLinks',
                'tags',
            )
            ->leftJoin('a.category', 'category')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.destinationLinks', 'destinationLinks')
            ->leftJoin('destinationLinks.destination', 'destinations')
            ->leftJoin('a.placeLinks', 'placeLinks')
            ->leftJoin('placeLinks.place', 'places')
            ->leftJoin('a.hikeLinks', 'hikeLinks')
            ->leftJoin('hikeLinks.hikeDraft', 'hikes')
            ->leftJoin('hikes.destination', 'hikeDestinations')
            ->leftJoin('a.cityVisitLinks', 'cityVisitLinks')
            ->leftJoin('cityVisitLinks.cityVisitDraft', 'cityVisits')
            ->leftJoin('cityVisits.destination', 'cityVisitDestinations')
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

    private function applySearch(QueryBuilder $queryBuilder, ?string $query): QueryBuilder
    {
        $normalizedQuery = $this->normalizeSearchQuery($query);

        if ($normalizedQuery === null) {
            return $queryBuilder;
        }

        return $queryBuilder
            ->andWhere('LOWER(a.title) LIKE :publicSearchQuery OR LOWER(a.excerpt) LIKE :publicSearchQuery')
            ->setParameter('publicSearchQuery', '%'.$normalizedQuery.'%');
    }

    private function normalizeSearchQuery(?string $query): ?string
    {
        $normalizedQuery = trim(mb_strtolower((string) $query));

        return $normalizedQuery === '' ? null : $normalizedQuery;
    }

    public function findLatestPublishedForHomepage(): ?Article
    {
        /** @var array{id: int}|null $latestArticleRow */
        $latestArticleRow = $this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.status = :status')
            ->andWhere('a.publishedAt IS NOT NULL')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestArticleRow === null) {
            return null;
        }

        /** @var Article|null $article */
        $article = $this->createQueryBuilder('a')
            ->addSelect('featuredImage', 'mediaLinks', 'mediaAssets', 'hikeLinks', 'hikes', 'hikeMediaLinks', 'hikeMediaAssets', 'cityVisitLinks', 'cityVisits', 'cityVisitMediaLinks', 'cityVisitMediaAssets')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('a.hikeLinks', 'hikeLinks')
            ->leftJoin('hikeLinks.hikeDraft', 'hikes')
            ->leftJoin('hikes.mediaLinks', 'hikeMediaLinks')
            ->leftJoin('hikeMediaLinks.mediaAsset', 'hikeMediaAssets')
            ->leftJoin('a.cityVisitLinks', 'cityVisitLinks')
            ->leftJoin('cityVisitLinks.cityVisitDraft', 'cityVisits')
            ->leftJoin('cityVisits.mediaLinks', 'cityVisitMediaLinks')
            ->leftJoin('cityVisitMediaLinks.mediaAsset', 'cityVisitMediaAssets')
            ->andWhere('a.id = :id')
            ->setParameter('id', $latestArticleRow['id'])
            ->orderBy('hikeLinks.position', 'ASC')
            ->addOrderBy('cityVisitLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('hikeMediaLinks.position', 'ASC')
            ->addOrderBy('cityVisitMediaLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $article;
    }

    public function findLatestPublishedWithMediaByDestination(Destination $destination): ?Article
    {
        /** @var array{id: int}|null $latestArticleRow */
        $latestArticleRow = $this->createQueryBuilder('a')
            ->select('a.id')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.destinationLinks', 'destinationLinks')
            ->leftJoin('destinationLinks.destination', 'destinations')
            ->leftJoin('a.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('a.hikeLinks', 'hikeLinks')
            ->leftJoin('hikeLinks.hikeDraft', 'hikes')
            ->leftJoin('hikes.geographicDestination', 'hikeGeographicDestinations')
            ->leftJoin('hikes.destination', 'hikeDestinations')
            ->leftJoin('a.cityVisitLinks', 'cityVisitLinks')
            ->leftJoin('cityVisitLinks.cityVisitDraft', 'cityVisits')
            ->leftJoin('cityVisits.geographicDestination', 'cityVisitGeographicDestinations')
            ->leftJoin('cityVisits.destination', 'cityVisitDestinations')
            ->andWhere('a.status = :status')
            ->andWhere('a.publishedAt IS NOT NULL')
            ->andWhere('featuredImage.mediaType = :mediaType OR mediaAssets.mediaType = :mediaType')
            ->andWhere(
                'destinations = :destination'
                . ' OR ((hikeGeographicDestinations = :destination OR (hikeGeographicDestinations IS NULL AND hikeDestinations = :destination)) AND hikes.status IN (:hikeStatuses))'
                . ' OR ((cityVisitGeographicDestinations = :destination OR (cityVisitGeographicDestinations IS NULL AND cityVisitDestinations = :destination)) AND cityVisits.status IN (:cityVisitStatuses))'
            )
            ->setParameter('destination', $destination)
            ->setParameter('status', ContentStatus::Published)
            ->setParameter('mediaType', MediaType::Image)
            ->setParameter('hikeStatuses', [
                HikeDraftStatus::Finished->value,
                HikeDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->setParameter('cityVisitStatuses', [
                CityVisitDraftStatus::Finished->value,
                CityVisitDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestArticleRow === null) {
            return null;
        }

        /** @var Article|null $article */
        $article = $this->createQueryBuilder('a')
            ->addSelect('featuredImage', 'destinationLinks', 'destinations', 'mediaLinks', 'mediaAssets', 'hikeLinks', 'hikes', 'hikeGeographicDestinations', 'hikeDestinations', 'cityVisitLinks', 'cityVisits', 'cityVisitGeographicDestinations', 'cityVisitDestinations')
            ->leftJoin('a.featuredImage', 'featuredImage')
            ->leftJoin('a.destinationLinks', 'destinationLinks')
            ->leftJoin('destinationLinks.destination', 'destinations')
            ->leftJoin('a.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('a.hikeLinks', 'hikeLinks')
            ->leftJoin('hikeLinks.hikeDraft', 'hikes')
            ->leftJoin('hikes.geographicDestination', 'hikeGeographicDestinations')
            ->leftJoin('hikes.destination', 'hikeDestinations')
            ->leftJoin('a.cityVisitLinks', 'cityVisitLinks')
            ->leftJoin('cityVisitLinks.cityVisitDraft', 'cityVisits')
            ->leftJoin('cityVisits.geographicDestination', 'cityVisitGeographicDestinations')
            ->leftJoin('cityVisits.destination', 'cityVisitDestinations')
            ->andWhere('a.id = :id')
            ->setParameter('id', $latestArticleRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $article;
    }
}
