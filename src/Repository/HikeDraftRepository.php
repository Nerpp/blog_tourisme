<?php

namespace App\Repository;

use App\Entity\HikeDraft;
use App\Entity\Destination;
use App\Entity\User;
use App\Enum\HikeDraftStatus;
use App\Enum\MediaType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HikeDraft> */
class HikeDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HikeDraft::class);
    }

    public function findCurrentDraftForUser(User $user): ?HikeDraft
    {
        /** @var HikeDraft|null $draft */
        $draft = $this->createQueryBuilder('h')
            ->andWhere('h.createdBy = :user')
            ->andWhere('h.status = :status')
            ->andWhere('h.finishedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', HikeDraftStatus::Draft)
            ->orderBy('h.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    public function findLatestFinishedForHomepage(): ?HikeDraft
    {
        /** @var array{id: int}|null $latestHikeRow */
        $latestHikeRow = $this->createQueryBuilder('h')
            ->select('h.id')
            ->andWhere('h.status = :status')
            ->andWhere('h.finishedAt IS NOT NULL')
            ->setParameter('status', HikeDraftStatus::Finished)
            ->orderBy('h.finishedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestHikeRow === null) {
            return null;
        }

        /** @var HikeDraft|null $draft */
        $draft = $this->createQueryBuilder('h')
            ->addSelect('mediaLinks', 'mediaAssets')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('h.id = :id')
            ->setParameter('id', $latestHikeRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    public function findPublicBySlug(string $slug): ?HikeDraft
    {
        /** @var HikeDraft|null $draft */
        $draft = $this->createQueryBuilder('h')
            ->addSelect('destination', 'geographicDestination', 'geographicDestinationParent', 'geographicDestinationGrandParent', 'geographicDestinationGreatGrandParent', 'destinationParent', 'destinationGrandParent', 'destinationGreatGrandParent', 'points', 'mediaLinks', 'mediaAssets', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('h.destination', 'destination')
            ->leftJoin('h.geographicDestination', 'geographicDestination')
            ->leftJoin('geographicDestination.parent', 'geographicDestinationParent')
            ->leftJoin('geographicDestinationParent.parent', 'geographicDestinationGrandParent')
            ->leftJoin('geographicDestinationGrandParent.parent', 'geographicDestinationGreatGrandParent')
            ->leftJoin('destination.parent', 'destinationParent')
            ->leftJoin('destinationParent.parent', 'destinationGrandParent')
            ->leftJoin('destinationGrandParent.parent', 'destinationGreatGrandParent')
            ->leftJoin('h.points', 'points')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('h.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('h.slug = :slug')
            ->andWhere('h.status IN (:statuses)')
            ->setParameter('slug', $slug)
            ->setParameter('statuses', [
                HikeDraftStatus::Finished,
                HikeDraftStatus::Converted,
            ])
            ->orderBy('points.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('articleLinks.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    /** @return list<HikeDraft> */
    public function findPublicForListing(?string $query = null, int $limit = 24): array
    {
        /** @var list<HikeDraft> $drafts */
        $drafts = $this->applyPublicSearch($this->createPublicListingQueryBuilder(), $query)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $drafts;
    }

    /** @return list<HikeDraft> */
    public function findPublicSuggestions(string $query, int $limit = 8): array
    {
        /** @var list<HikeDraft> $drafts */
        $drafts = $this->applyPublicSearch(
            $this->createQueryBuilder('h')
                ->addSelect('destination', 'geographicDestination')
                ->leftJoin('h.destination', 'destination')
                ->leftJoin('h.geographicDestination', 'geographicDestination')
                ->andWhere('h.status IN (:statuses)')
                ->setParameter('statuses', [
                    HikeDraftStatus::Finished->value,
                    HikeDraftStatus::Converted->value,
                ], ArrayParameterType::STRING)
                ->orderBy('h.finishedAt', 'DESC')
                ->addOrderBy('h.id', 'DESC'),
            $query,
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $drafts;
    }

    public function findLatestPublicWithMediaByDestination(Destination $destination): ?HikeDraft
    {
        /** @var array{id: int}|null $latestHikeRow */
        $latestHikeRow = $this->createQueryBuilder('h')
            ->select('h.id')
            ->innerJoin('h.mediaLinks', 'mediaLinks')
            ->innerJoin('mediaLinks.mediaAsset', 'mediaAssets', 'WITH', 'mediaAssets.mediaType = :mediaType')
            ->leftJoin('h.geographicDestination', 'geographicDestination')
            ->andWhere('geographicDestination = :destination OR (geographicDestination IS NULL AND h.destination = :destination)')
            ->andWhere('h.status IN (:statuses)')
            ->setParameter('destination', $destination)
            ->setParameter('mediaType', MediaType::Image)
            ->setParameter('statuses', [
                HikeDraftStatus::Finished,
                HikeDraftStatus::Converted,
            ])
            ->orderBy('h.finishedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestHikeRow === null) {
            return null;
        }

        /** @var HikeDraft|null $draft */
        $draft = $this->createQueryBuilder('h')
            ->addSelect('mediaLinks', 'mediaAssets')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('h.id = :id')
            ->setParameter('id', $latestHikeRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<HikeDraft>
     */
    public function findPublicByDestinationIds(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        /** @var list<HikeDraft> $drafts */
        $drafts = $this->createQueryBuilder('h')
            ->addSelect('destination', 'geographicDestination', 'mediaLinks', 'mediaAssets', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('h.destination', 'destination')
            ->leftJoin('h.geographicDestination', 'geographicDestination')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('h.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('geographicDestination.id IN (:destinationIds) OR (geographicDestination.id IS NULL AND destination.id IN (:destinationIds))')
            ->andWhere('h.status IN (:statuses)')
            ->setParameter('destinationIds', $destinationIds, ArrayParameterType::INTEGER)
            ->setParameter('statuses', [
                HikeDraftStatus::Finished->value,
                HikeDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->orderBy('h.finishedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $drafts;
    }

    private function createPublicListingQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('h')
            ->addSelect('destination', 'geographicDestination', 'mediaLinks', 'mediaAssets', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('h.destination', 'destination')
            ->leftJoin('h.geographicDestination', 'geographicDestination')
            ->leftJoin('h.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('h.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('h.status IN (:statuses)')
            ->setParameter('statuses', [
                HikeDraftStatus::Finished->value,
                HikeDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->orderBy('h.finishedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC');
    }

    private function applyPublicSearch(QueryBuilder $queryBuilder, ?string $query): QueryBuilder
    {
        $normalizedQuery = $this->normalizeSearchQuery($query);

        if ($normalizedQuery === null) {
            return $queryBuilder;
        }

        return $queryBuilder
            ->andWhere('LOWER(h.title) LIKE :publicSearchQuery OR LOWER(destination.name) LIKE :publicSearchQuery OR LOWER(geographicDestination.name) LIKE :publicSearchQuery OR LOWER(h.detectedCommuneName) LIKE :publicSearchQuery')
            ->setParameter('publicSearchQuery', '%'.$normalizedQuery.'%');
    }

    private function normalizeSearchQuery(?string $query): ?string
    {
        $normalizedQuery = trim(mb_strtolower((string) $query));

        return $normalizedQuery === '' ? null : $normalizedQuery;
    }
}
