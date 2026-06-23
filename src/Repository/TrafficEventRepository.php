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

        $statistics = [];
        foreach ($rows as $row) {
            $routeName = $row['routeName'] ?? null;
            $contentType = $row['contentType'] ?? null;
            $contentTitle = $row['contentTitle'] ?? null;
            if (
                !array_key_exists('routeName', $row)
                || !array_key_exists('contentType', $row)
                || !array_key_exists('contentTitle', $row)
                || !is_string($row['path'] ?? null)
                || ($routeName !== null && !is_string($routeName))
                || ($contentType !== null && !is_string($contentType))
                || ($contentTitle !== null && !is_string($contentTitle))
            ) {
                continue;
            }

            $views = $this->nonNegativeInt($row['views'] ?? null);
            $visitors = $this->nonNegativeInt($row['visitors'] ?? null);
            if ($views === null || $visitors === null) {
                continue;
            }

            $statistics[] = [
                'path' => $row['path'],
                'routeName' => $routeName,
                'contentType' => $contentType,
                'contentTitle' => $contentTitle,
                'views' => $views,
                'visitors' => $visitors,
            ];
        }

        return $statistics;
    }

    /** @return list<array{contentId: ?int, contentTitle: ?string, path: string, views: int, visitors: int}> */
    public function topContent(string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT content_id AS contentId, content_title AS contentTitle, MIN(path) AS path, COUNT(id) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to AND content_type = :contentType'.$this->botSql($includeBots).' GROUP BY content_type, content_id, content_title ORDER BY views DESC, visitors DESC LIMIT :limit',
            array_replace($this->sqlParams($from, $to, $limit), ['contentType' => $type]),
            $this->sqlTypes(true, ['contentType' => ParameterType::STRING]),
        )->fetchAllAssociative();

        $statistics = [];
        foreach ($rows as $row) {
            $contentTitle = $row['contentTitle'] ?? null;
            if (
                !array_key_exists('contentId', $row)
                || !array_key_exists('contentTitle', $row)
                || !$this->isNullablePositiveInt($row['contentId'])
                || ($contentTitle !== null && !is_string($contentTitle))
                || !is_string($row['path'] ?? null)
            ) {
                continue;
            }

            $contentId = $this->positiveInt($row['contentId'] ?? null);
            $views = $this->nonNegativeInt($row['views'] ?? null);
            $visitors = $this->nonNegativeInt($row['visitors'] ?? null);
            if ($views === null || $visitors === null) {
                continue;
            }

            $statistics[] = [
                'contentId' => $contentId,
                'contentTitle' => $contentTitle,
                'path' => $row['path'],
                'views' => $views,
                'visitors' => $visitors,
            ];
        }

        return $statistics;
    }

    /** @return list<array{source: string, views: int}> */
    public function referrers(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(referrer_host, utm_source, "Direct") AS source, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY source ORDER BY views DESC LIMIT :limit',
            $this->sqlParams($from, $to, $limit),
            $this->sqlTypes(true),
        )->fetchAllAssociative();

        $statistics = [];
        foreach ($rows as $row) {
            $views = $this->nonNegativeInt($row['views'] ?? null);
            if (!is_string($row['source'] ?? null) || $views === null) {
                continue;
            }

            $statistics[] = ['source' => $row['source'], 'views' => $views];
        }

        return $statistics;
    }

    /** @return list<array{deviceType: string, views: int}> */
    public function devices(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(device_type, "unknown") AS deviceType, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY deviceType ORDER BY views DESC',
            $this->sqlParams($from, $to),
            $this->sqlTypes(),
        )->fetchAllAssociative();

        $statistics = [];
        foreach ($rows as $row) {
            $views = $this->nonNegativeInt($row['views'] ?? null);
            if (!is_string($row['deviceType'] ?? null) || $views === null) {
                continue;
            }

            $statistics[] = ['deviceType' => $row['deviceType'], 'views' => $views];
        }

        return $statistics;
    }

    /** @return list<array{browserFamily: string, views: int}> */
    public function browsers(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(browser_family, "unknown") AS browserFamily, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY browserFamily ORDER BY views DESC',
            $this->sqlParams($from, $to),
            $this->sqlTypes(),
        )->fetchAllAssociative();

        $statistics = [];
        foreach ($rows as $row) {
            $views = $this->nonNegativeInt($row['views'] ?? null);
            if (!is_string($row['browserFamily'] ?? null) || $views === null) {
                continue;
            }

            $statistics[] = ['browserFamily' => $row['browserFamily'], 'views' => $views];
        }

        return $statistics;
    }

    /** @return list<array{statusCode: int, views: int}> */
    public function statusCodes(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT status_code AS statusCode, COUNT(id) AS views FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to'.$this->botSql($includeBots).' GROUP BY status_code ORDER BY views DESC',
            $this->sqlParams($from, $to),
            $this->sqlTypes(),
        )->fetchAllAssociative();

        $statistics = [];
        foreach ($rows as $row) {
            $statusCode = $this->nonNegativeInt($row['statusCode'] ?? null);
            $views = $this->nonNegativeInt($row['views'] ?? null);
            if ($statusCode === null || $views === null) {
                continue;
            }

            $statistics[] = ['statusCode' => $statusCode, 'views' => $views];
        }

        return $statistics;
    }

    /** @return list<array{path: string, hits: int, lastSeen: \DateTimeImmutable}> */
    public function errors404(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 20, bool $includeBots = false): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT path, COUNT(id) AS hits, MAX(occurred_at) AS lastSeen FROM traffic_event WHERE occurred_at >= :from AND occurred_at < :to AND status_code = 404'.$this->botSql($includeBots).' GROUP BY path ORDER BY hits DESC, lastSeen DESC LIMIT :limit',
            $this->sqlParams($from, $to, $limit),
            $this->sqlTypes(true),
        )->fetchAllAssociative();

        $statistics = [];
        foreach ($rows as $row) {
            $hits = $this->nonNegativeInt($row['hits'] ?? null);
            $lastSeen = $this->sqlDate($row['lastSeen'] ?? null);
            if (!is_string($row['path'] ?? null) || $hits === null || $lastSeen === null) {
                continue;
            }

            $statistics[] = [
                'path' => $row['path'],
                'hits' => $hits,
                'lastSeen' => $lastSeen,
            ];
        }

        return $statistics;
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

        /** @var list<TrafficEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
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

        $deleted = $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.occurredAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        return $this->nonNegativeInt($deleted) ?? 0;
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

        $statistics = [];
        foreach ($rows as $row) {
            $views = $this->nonNegativeInt($row['views'] ?? null);
            $visitors = $this->nonNegativeInt($row['visitors'] ?? null);
            if (!is_string($row['period'] ?? null) || $views === null || $visitors === null) {
                continue;
            }

            $statistics[] = [
                'period' => $row['period'],
                'views' => $views,
                'visitors' => $visitors,
            ];
        }

        return $statistics;
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

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer >= 0 ? $integer : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = $this->nonNegativeInt($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function isNullablePositiveInt(mixed $value): bool
    {
        return $value === null || $this->positiveInt($value) !== null;
    }

    private function sqlDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$date instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            return null;
        }

        return $date;
    }
}
