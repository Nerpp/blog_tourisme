<?php

namespace App\Repository;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\MediaType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CityVisitDraft> */
class CityVisitDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CityVisitDraft::class);
    }

    /** @return list<CityVisitDraft> */
    public function findPublicForSitemap(): array
    {
        /** @var list<CityVisitDraft> $cityVisits */
        $cityVisits = $this->createQueryBuilder('c')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('c.slug IS NOT NULL')
            ->andWhere('c.slug != :emptySlug')
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished->value,
                CityVisitDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->setParameter('emptySlug', '')
            ->orderBy('c.slug', 'ASC')
            ->getQuery()
            ->getResult();

        return $cityVisits;
    }

    public function findCurrentDraftForUser(User $user): ?CityVisitDraft
    {
        /** @var CityVisitDraft|null $draft */
        $draft = $this->createQueryBuilder('c')
            ->andWhere('c.createdBy = :user')
            ->andWhere('c.status = :status')
            ->andWhere('c.finishedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', CityVisitDraftStatus::Draft)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    public function findLatestFinishedForHomepage(): ?CityVisitDraft
    {
        /** @var array{id: int}|null $latestCityVisitRow */
        $latestCityVisitRow = $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.status = :status')
            ->andWhere('c.finishedAt IS NOT NULL')
            ->setParameter('status', CityVisitDraftStatus::Finished)
            ->orderBy('c.finishedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestCityVisitRow === null) {
            return null;
        }

        /** @var CityVisitDraft|null $draft */
        $draft = $this->createQueryBuilder('c')
            ->addSelect('mediaLinks', 'mediaAssets')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('c.id = :id')
            ->setParameter('id', $latestCityVisitRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    public function findPublicBySlug(string $slug): ?CityVisitDraft
    {
        /** @var CityVisitDraft|null $draft */
        $draft = $this->createDetailBySlugQueryBuilder($slug)
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished,
                CityVisitDraftStatus::Converted,
            ])
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    public function findOneBySlugWithRelations(string $slug): ?CityVisitDraft
    {
        /** @var CityVisitDraft|null $draft */
        $draft = $this->createDetailBySlugQueryBuilder($slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    private function createDetailBySlugQueryBuilder(string $slug): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->addSelect('destination', 'geographicDestination', 'geographicDestinationParent', 'geographicDestinationGrandParent', 'geographicDestinationGreatGrandParent', 'destinationParent', 'destinationGrandParent', 'destinationGreatGrandParent', 'points', 'mediaLinks', 'mediaAssets', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('c.destination', 'destination')
            ->leftJoin('c.geographicDestination', 'geographicDestination')
            ->leftJoin('geographicDestination.parent', 'geographicDestinationParent')
            ->leftJoin('geographicDestinationParent.parent', 'geographicDestinationGrandParent')
            ->leftJoin('geographicDestinationGrandParent.parent', 'geographicDestinationGreatGrandParent')
            ->leftJoin('destination.parent', 'destinationParent')
            ->leftJoin('destinationParent.parent', 'destinationGrandParent')
            ->leftJoin('destinationGrandParent.parent', 'destinationGreatGrandParent')
            ->leftJoin('c.points', 'points')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('c.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('c.slug = :slug')
            ->setParameter('slug', $slug)
            ->orderBy('points.position', 'ASC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('articleLinks.position', 'ASC');
    }

    /** @return list<CityVisitDraft> */
    public function findPublicForListing(?string $query = null, int $limit = 24): array
    {
        /** @var list<CityVisitDraft> $drafts */
        $drafts = $this->applyPublicSearch($this->createPublicListingQueryBuilder(), $query)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $drafts;
    }

    /** @return list<CityVisitDraft> */
    public function findPublicSuggestions(string $query, int $limit = 8): array
    {
        /** @var list<CityVisitDraft> $drafts */
        $drafts = $this->applyPublicSearch(
            $this->createQueryBuilder('c')
                ->addSelect('destination', 'geographicDestination')
                ->leftJoin('c.destination', 'destination')
                ->leftJoin('c.geographicDestination', 'geographicDestination')
                ->andWhere('c.status IN (:statuses)')
                ->setParameter('statuses', [
                    CityVisitDraftStatus::Finished->value,
                    CityVisitDraftStatus::Converted->value,
                ], ArrayParameterType::STRING)
                ->orderBy('c.finishedAt', 'DESC')
                ->addOrderBy('c.id', 'DESC'),
            $query,
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $drafts;
    }

    public function findLatestPublicWithMediaByDestination(Destination $destination): ?CityVisitDraft
    {
        /** @var array{id: int}|null $latestCityVisitRow */
        $latestCityVisitRow = $this->createQueryBuilder('c')
            ->select('c.id')
            ->innerJoin('c.mediaLinks', 'mediaLinks')
            ->innerJoin('mediaLinks.mediaAsset', 'mediaAssets', 'WITH', 'mediaAssets.mediaType = :mediaType')
            ->leftJoin('c.geographicDestination', 'geographicDestination')
            ->andWhere('geographicDestination = :destination OR (geographicDestination IS NULL AND c.destination = :destination)')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('destination', $destination)
            ->setParameter('mediaType', MediaType::Image)
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished,
                CityVisitDraftStatus::Converted,
            ])
            ->orderBy('c.finishedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestCityVisitRow === null) {
            return null;
        }

        /** @var CityVisitDraft|null $draft */
        $draft = $this->createQueryBuilder('c')
            ->addSelect('mediaLinks', 'mediaAssets')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->andWhere('c.id = :id')
            ->setParameter('id', $latestCityVisitRow['id'])
            ->orderBy('mediaLinks.position', 'ASC')
            ->addOrderBy('mediaLinks.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<CityVisitDraft>
     */
    public function findPublicByDestinationIds(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        /** @var list<CityVisitDraft> $drafts */
        $drafts = $this->createQueryBuilder('c')
            ->addSelect('destination', 'geographicDestination', 'mediaLinks', 'mediaAssets', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('c.destination', 'destination')
            ->leftJoin('c.geographicDestination', 'geographicDestination')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('c.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('geographicDestination.id IN (:destinationIds) OR (geographicDestination.id IS NULL AND destination.id IN (:destinationIds))')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('destinationIds', $destinationIds, ArrayParameterType::INTEGER)
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished->value,
                CityVisitDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->orderBy('c.finishedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $drafts;
    }

    private function createPublicListingQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->addSelect('destination', 'geographicDestination', 'mediaLinks', 'mediaAssets', 'articleLinks', 'articles', 'articleCategories', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('c.destination', 'destination')
            ->leftJoin('c.geographicDestination', 'geographicDestination')
            ->leftJoin('c.mediaLinks', 'mediaLinks')
            ->leftJoin('mediaLinks.mediaAsset', 'mediaAssets')
            ->leftJoin('c.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles')
            ->leftJoin('articles.category', 'articleCategories')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [
                CityVisitDraftStatus::Finished->value,
                CityVisitDraftStatus::Converted->value,
            ], ArrayParameterType::STRING)
            ->orderBy('c.finishedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->addOrderBy('mediaLinks.position', 'ASC');
    }

    private function applyPublicSearch(QueryBuilder $queryBuilder, ?string $query): QueryBuilder
    {
        $normalizedQuery = $this->normalizeSearchQuery($query);

        if ($normalizedQuery === null) {
            return $queryBuilder;
        }

        return $queryBuilder
            ->andWhere('LOWER(c.title) LIKE :publicSearchQuery OR LOWER(destination.name) LIKE :publicSearchQuery OR LOWER(geographicDestination.name) LIKE :publicSearchQuery OR LOWER(c.detectedCommuneName) LIKE :publicSearchQuery')
            ->setParameter('publicSearchQuery', '%'.$normalizedQuery.'%');
    }

    private function normalizeSearchQuery(?string $query): ?string
    {
        $normalizedQuery = trim(mb_strtolower((string) $query));

        return $normalizedQuery === '' ? null : $normalizedQuery;
    }
}
