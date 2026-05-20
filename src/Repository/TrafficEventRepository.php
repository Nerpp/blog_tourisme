<?php

namespace App\Repository;

use App\Entity\TrafficEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TrafficEvent> */
final class TrafficEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrafficEvent::class);
    }

    public function countPageViews(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): int
    {
        return (int) $this->baseQuery($from, $to, $includeBots)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countApproxVisitors(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): int
    {
        return (int) $this->baseQuery($from, $to, $includeBots)
            ->select('COUNT(DISTINCT e.visitorHash)')
            ->andWhere('e.visitorHash IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countToday(bool $includeBots = false): int
    {
        $from = new \DateTimeImmutable('today');
        $to = $from->modify('+1 day');

        return $this->countPageViews($from, $to, $includeBots);
    }

    public function countLast7Days(bool $includeBots = false): int
    {
        return $this->countPageViews(new \DateTimeImmutable('-6 days midnight'), new \DateTimeImmutable('tomorrow'), $includeBots);
    }

    public function countLast30Days(bool $includeBots = false): int
    {
        return $this->countPageViews(new \DateTimeImmutable('-29 days midnight'), new \DateTimeImmutable('tomorrow'), $includeBots);
    }

    /** @return list<array{path: string, routeName: ?string, contentType: ?string, contentTitle: ?string, views: int, visitors: int}> */
    public function topPages(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT path, route_name AS routeName, content_type AS contentType, content_title AS contentTitle, COUNT(id) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY path, route_name, content_type, content_title ORDER BY views DESC, visitors DESC LIMIT :limit',
            $this->sqlParams($from, $to, $limit),
            $this->sqlTypes(true),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['views', 'visitors']);
    }

    /** @return list<array{contentId: ?int, contentTitle: ?string, path: string, views: int, visitors: int}> */
    public function topContent(string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT content_id AS contentId, content_title AS contentTitle, MIN(path) AS path, COUNT(id) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to AND content_type = :contentType'.$this->botSql($includeBots).' GROUP BY content_type, content_id, content_title ORDER BY views DESC, visitors DESC LIMIT :limit',
            array_replace($this->sqlParams($from, $to, $limit), ['contentType' => $type]),
            $this->sqlTypes(true, ['contentType' => ParameterType::STRING]),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['contentId', 'views', 'visitors']);
    }

    /** @return list<array{source: string, views: int}> */
    public function referrers(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(referrer_host, utm_source, "Direct") AS source, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY source ORDER BY views DESC LIMIT :limit',
            $this->sqlParams($from, $to, $limit),
            $this->sqlTypes(true),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['views']);
    }

    /** @return list<array{deviceType: string, views: int}> */
    public function devices(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(device_type, "unknown") AS deviceType, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY deviceType ORDER BY views DESC',
            $this->sqlParams($from, $to),
            $this->sqlTypes(),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['views']);
    }

    /** @return list<array{browserFamily: string, views: int}> */
    public function browsers(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(browser_family, "unknown") AS browserFamily, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY browserFamily ORDER BY views DESC',
            $this->sqlParams($from, $to),
            $this->sqlTypes(),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['views']);
    }

    /** @return list<array{statusCode: int, views: int}> */
    public function statusCodes(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT status_code AS statusCode, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY status_code ORDER BY views DESC',
            $this->sqlParams($from, $to),
            $this->sqlTypes(),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['statusCode', 'views']);
    }

    /** @return list<array{path: string, hits: int, lastSeen: \DateTimeImmutable}> */
    public function errors404(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 20, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT path, COUNT(id) AS hits, MAX(occurred_at) AS lastSeen FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to AND status_code = 404'.$this->botSql($includeBots).' GROUP BY path ORDER BY hits DESC, lastSeen DESC LIMIT :limit',
            $this->sqlParams($from, $to, $limit),
            $this->sqlTypes(true),
        )->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['hits']);
    }

    /** @return list<array{period: string, views: int, visitors: int}> */
    public function trafficByDay(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        return $this->aggregateByDateFormat('%Y-%m-%d', $from, $to, $includeBots);
    }

    /** @return list<array{period: string, views: int, visitors: int}> */
    public function trafficByHour(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        return $this->aggregateByDateFormat('%Y-%m-%d %H:00', $from, $to, $includeBots);
    }

    /** @return list<TrafficEvent> */
    public function latestEvents(int $limit = 25, bool $includeBots = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit);

        if (!$includeBots) {
            $qb->andWhere('e.isBot = false');
        }

        return $qb->getQuery()->getResult();
    }

    public function pruneOlderThan(\DateTimeImmutable $threshold, bool $dryRun = true): int
    {
        if ($dryRun) {
            return (int) $this->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->andWhere('e.occurredAt < :threshold')
                ->setParameter('threshold', $threshold)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.occurredAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    private function baseQuery(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.occurredAt >= :from')
            ->andWhere('e.occurredAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (!$includeBots) {
            $qb->andWhere('e.isBot = false');
        }

        return $qb;
    }

    /** @return list<array{period: string, views: int, visitors: int}> */
    private function aggregateByDateFormat(string $format, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots): array
    {
        $sql = sprintf(
            'SELECT DATE_FORMAT(occurred_at, :format) AS period, COUNT(id) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to%s GROUP BY period ORDER BY period ASC',
            $includeBots ? '' : ' AND is_bot = 0',
        );

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'format' => $format,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ], [
            'format' => ParameterType::STRING,
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
        ])->fetchAllAssociative();

        return $this->normalizeIntegerColumns($rows, ['views', 'visitors']);
    }

    /** @return array{from: string, to: string, limit?: int} */
    private function sqlParams(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $limit = null): array
    {
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        return $params;
    }

    private function botSql(bool $includeBots): string
    {
        return $includeBots ? '' : ' AND is_bot = 0';
    }

    /**
     * @param array<string, ParameterType> $extraTypes
     *
     * @return array<string, ParameterType>
     */
    private function sqlTypes(bool $withLimit = false, array $extraTypes = []): array
    {
        $types = [
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
        ];

        if ($withLimit) {
            $types['limit'] = ParameterType::INTEGER;
        }

        return array_replace($types, $extraTypes);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeIntegerColumns(array $rows, array $columns): array
    {
        foreach ($rows as &$row) {
            foreach ($columns as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = (int) $row[$column];
                }
            }
        }

        return $rows;
    }
}
