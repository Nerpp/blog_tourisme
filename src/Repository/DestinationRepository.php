<?php

namespace App\Repository;

use App\Entity\Destination;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * @param list<Destination> $rootDestinations
     *
     * @return array<int, array{
     *     places: int,
     *     articles: int,
     *     hikes: int,
     *     city_visits: int,
     *     total: int
     * }>
     */
    public function findCumulativeContentCountsForTree(array $rootDestinations): array
    {
        $destinations = $this->flattenDestinationTree($rootDestinations);
        $destinationIds = array_values(array_unique(array_filter(
            array_map(static fn (Destination $destination): ?int => $destination->getId(), $destinations),
            static fn (?int $id): bool => $id !== null,
        )));

        if ($destinationIds === []) {
            return [];
        }

        $childrenMap = $this->buildChildrenMap($destinations);
        $directCounts = [];
        $places = $this->countPublishedPlacesByDestinationIds($destinationIds);
        $articles = $this->countPublishedArticlesByDestinationIds($destinationIds);
        $hikes = $this->countPublicHikesByDestinationIds($destinationIds);
        $cityVisits = $this->countPublicCityVisitsByDestinationIds($destinationIds);

        foreach ($destinationIds as $destinationId) {
            $directCounts[$destinationId] = [
                'places' => $places[$destinationId] ?? 0,
                'articles' => $articles[$destinationId] ?? 0,
                'hikes' => $hikes[$destinationId] ?? 0,
                'city_visits' => $cityVisits[$destinationId] ?? 0,
                'total' => 0,
            ];
            $directCounts[$destinationId]['total'] = $directCounts[$destinationId]['places']
                + $directCounts[$destinationId]['articles']
                + $directCounts[$destinationId]['hikes']
                + $directCounts[$destinationId]['city_visits'];
        }

        $memo = [];
        foreach ($destinationIds as $destinationId) {
            $this->computeCumulativeCounts($destinationId, $childrenMap, $directCounts, $memo);
        }

        return $memo;
    }

    /**
     * @param list<Destination> $rootDestinations
     *
     * @return list<Destination>
     */
    public function findDestinationSuggestionsForTree(array $rootDestinations): array
    {
        $destinations = $this->flattenDestinationTree($rootDestinations);
        usort($destinations, static fn (Destination $first, Destination $second): int => strcasecmp(
            $first->getName() ?? '',
            $second->getName() ?? '',
        ));

        return $destinations;
    }

    /** @return list<int> */
    public function findDestinationAndDescendantIds(Destination $destination): array
    {
        $rootId = $destination->getId();
        if ($rootId === null) {
            return [];
        }

        $destinationIds = [$rootId];
        $parentIds = [$rootId];
        $connection = $this->getEntityManager()->getConnection();

        while (true) {
            $childIds = array_map(
                'intval',
                $connection->executeQuery(
                    'SELECT id FROM destination WHERE parent_id IN (:parentIds)',
                    ['parentIds' => $parentIds],
                    ['parentIds' => ArrayParameterType::INTEGER],
                )->fetchFirstColumn(),
            );

            $childIds = array_values(array_diff($childIds, $destinationIds));
            if ($childIds === []) {
                break;
            }

            $destinationIds = array_values(array_unique([...$destinationIds, ...$childIds]));
            $parentIds = $childIds;
        }

        return $destinationIds;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Destination>
     */
    public function findWithParentsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->addSelect('parent', 'grandParent', 'greatGrandParent')
            ->leftJoin('d.parent', 'parent')
            ->leftJoin('parent.parent', 'grandParent')
            ->leftJoin('grandParent.parent', 'greatGrandParent')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->orderBy('d.name', 'ASC')
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
        $rows = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.parent IS NOT NULL')
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($ids === []) {
            return [];
        }

        $destinations = $this->createQueryBuilder('d')
            ->addSelect('parent', 'places', 'placeFeaturedImages', 'placeMediaLinks', 'placeMediaAssets', 'articleLinks', 'articles', 'articleFeaturedImages', 'articleMediaLinks', 'articleMediaAssets')
            ->leftJoin('d.parent', 'parent')
            ->leftJoin('d.places', 'places', 'WITH', 'places.status = :published')
            ->leftJoin('places.featuredImage', 'placeFeaturedImages')
            ->leftJoin('places.mediaLinks', 'placeMediaLinks')
            ->leftJoin('placeMediaLinks.mediaAsset', 'placeMediaAssets')
            ->leftJoin('d.articleLinks', 'articleLinks')
            ->leftJoin('articleLinks.article', 'articles', 'WITH', 'articles.status = :published')
            ->leftJoin('articles.featuredImage', 'articleFeaturedImages')
            ->leftJoin('articles.mediaLinks', 'articleMediaLinks')
            ->leftJoin('articleMediaLinks.mediaAsset', 'articleMediaAssets')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->setParameter('published', ContentStatus::Published)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->addOrderBy('placeMediaLinks.position', 'ASC')
            ->addOrderBy('articleLinks.position', 'ASC')
            ->addOrderBy('articleMediaLinks.position', 'ASC')
            ->getQuery()
            ->getResult();

        $positionById = array_flip($ids);
        usort($destinations, static fn (Destination $first, Destination $second): int => ($positionById[$first->getId()] ?? 0) <=> ($positionById[$second->getId()] ?? 0));

        return $destinations;
    }

    /**
     * @param list<Destination> $destinations
     *
     * @return list<Destination>
     */
    private function flattenDestinationTree(array $destinations): array
    {
        $flattened = [];
        $seenIds = [];

        $append = function (Destination $destination) use (&$append, &$flattened, &$seenIds): void {
            $id = $destination->getId();
            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    return;
                }

                $seenIds[$id] = true;
            }

            $flattened[] = $destination;

            foreach ($destination->getChildren() as $child) {
                $append($child);
            }
        };

        foreach ($destinations as $destination) {
            $append($destination);
        }

        return $flattened;
    }

    /**
     * @param list<Destination> $destinations
     *
     * @return array<int, list<int>>
     */
    private function buildChildrenMap(array $destinations): array
    {
        $childrenMap = [];
        $knownIds = [];

        foreach ($destinations as $destination) {
            $id = $destination->getId();
            if ($id !== null) {
                $knownIds[$id] = true;
                $childrenMap[$id] = [];
            }
        }

        foreach ($destinations as $destination) {
            $id = $destination->getId();
            if ($id === null) {
                continue;
            }

            foreach ($destination->getChildren() as $child) {
                $childId = $child->getId();
                if ($childId !== null && isset($knownIds[$childId])) {
                    $childrenMap[$id][] = $childId;
                }
            }
        }

        return $childrenMap;
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return array<int, int>
     */
    private function countPublishedPlacesByDestinationIds(array $destinationIds): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT p.destination_id, COUNT(p.id) AS content_count
                FROM place p
                WHERE p.destination_id IN (:destinationIds)
                AND p.status = :status
                GROUP BY p.destination_id
            SQL,
            [
                'destinationIds' => $destinationIds,
                'status' => ContentStatus::Published->value,
            ],
            [
                'destinationIds' => ArrayParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return $this->mapCountRowsByDestinationId($rows);
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return array<int, int>
     */
    private function countPublishedArticlesByDestinationIds(array $destinationIds): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT ad.destination_id, COUNT(DISTINCT a.id) AS content_count
                FROM article_destination ad
                INNER JOIN article a ON a.id = ad.article_id
                WHERE ad.destination_id IN (:destinationIds)
                AND a.status = :status
                GROUP BY ad.destination_id
            SQL,
            [
                'destinationIds' => $destinationIds,
                'status' => ContentStatus::Published->value,
            ],
            [
                'destinationIds' => ArrayParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return $this->mapCountRowsByDestinationId($rows);
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return array<int, int>
     */
    private function countPublicHikesByDestinationIds(array $destinationIds): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT COALESCE(h.geographic_destination_id, h.destination_id) AS destination_id, COUNT(h.id) AS content_count
                FROM hike_draft h
                WHERE COALESCE(h.geographic_destination_id, h.destination_id) IN (:destinationIds)
                AND h.status IN (:statuses)
                GROUP BY COALESCE(h.geographic_destination_id, h.destination_id)
            SQL,
            [
                'destinationIds' => $destinationIds,
                'statuses' => [
                    HikeDraftStatus::Finished->value,
                    HikeDraftStatus::Converted->value,
                ],
            ],
            [
                'destinationIds' => ArrayParameterType::INTEGER,
                'statuses' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return $this->mapCountRowsByDestinationId($rows);
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return array<int, int>
     */
    private function countPublicCityVisitsByDestinationIds(array $destinationIds): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT COALESCE(c.geographic_destination_id, c.destination_id) AS destination_id, COUNT(c.id) AS content_count
                FROM city_visit_draft c
                WHERE COALESCE(c.geographic_destination_id, c.destination_id) IN (:destinationIds)
                AND c.status IN (:statuses)
                GROUP BY COALESCE(c.geographic_destination_id, c.destination_id)
            SQL,
            [
                'destinationIds' => $destinationIds,
                'statuses' => [
                    CityVisitDraftStatus::Finished->value,
                    CityVisitDraftStatus::Converted->value,
                ],
            ],
            [
                'destinationIds' => ArrayParameterType::INTEGER,
                'statuses' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return $this->mapCountRowsByDestinationId($rows);
    }

    /**
     * @param array<int, array{destination_id: int|string, content_count: int|string}> $rows
     *
     * @return array<int, int>
     */
    private function mapCountRowsByDestinationId(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['destination_id']] = (int) $row['content_count'];
        }

        return $counts;
    }

    /**
     * @param array<int, list<int>> $childrenMap
     * @param array<int, array{places: int, articles: int, hikes: int, city_visits: int, total: int}> $directCounts
     * @param array<int, array{places: int, articles: int, hikes: int, city_visits: int, total: int}> $memo
     *
     * @return array{places: int, articles: int, hikes: int, city_visits: int, total: int}
     */
    private function computeCumulativeCounts(int $destinationId, array $childrenMap, array $directCounts, array &$memo): array
    {
        if (isset($memo[$destinationId])) {
            return $memo[$destinationId];
        }

        $counts = $directCounts[$destinationId] ?? [
            'places' => 0,
            'articles' => 0,
            'hikes' => 0,
            'city_visits' => 0,
            'total' => 0,
        ];

        foreach ($childrenMap[$destinationId] ?? [] as $childId) {
            $childCounts = $this->computeCumulativeCounts($childId, $childrenMap, $directCounts, $memo);
            $counts['places'] += $childCounts['places'];
            $counts['articles'] += $childCounts['articles'];
            $counts['hikes'] += $childCounts['hikes'];
            $counts['city_visits'] += $childCounts['city_visits'];
        }

        $counts['total'] = $counts['places'] + $counts['articles'] + $counts['hikes'] + $counts['city_visits'];
        $memo[$destinationId] = $counts;

        return $counts;
    }
}
