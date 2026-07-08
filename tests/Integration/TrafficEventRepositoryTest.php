<?php

namespace App\Tests\Integration;

use App\Entity\TrafficEvent;
use App\Repository\TrafficEventRepository;

final class TrafficEventRepositoryTest extends IntegrationTestCase
{
    public function testStatisticsReturnTheirDocumentedShapes(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-06-20 10:15:00');
        $event = (new TrafficEvent())
            ->setOccurredAt($occurredAt)
            ->setPath('/traffic-shapes')
            ->setContentType('article')
            ->setStatusCode(404)
            ->setVisitorHash('traffic-shapes-visitor');
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $repository = $this->service(TrafficEventRepository::class);
        self::assertInstanceOf(TrafficEventRepository::class, $repository);
        $from = $occurredAt->modify('-1 hour');
        $to = $occurredAt->modify('+1 hour');

        self::assertSame([[
            'path' => '/traffic-shapes',
            'routeName' => null,
            'contentType' => 'article',
            'contentTitle' => null,
            'views' => 1,
            'visitors' => 1,
        ]], $repository->topPages($from, $to));
        self::assertSame([[
            'contentId' => null,
            'contentTitle' => null,
            'path' => '/traffic-shapes',
            'views' => 1,
            'visitors' => 1,
        ]], $repository->topContent('article', $from, $to));
        self::assertSame([['source' => 'Direct', 'views' => 1]], $repository->referrers($from, $to));
        self::assertSame([['deviceType' => 'unknown', 'views' => 1]], $repository->devices($from, $to));
        self::assertSame([['browserFamily' => 'unknown', 'views' => 1]], $repository->browsers($from, $to));
        self::assertSame([['statusCode' => 404, 'views' => 1]], $repository->statusCodes($from, $to));

        $errors404 = $repository->errors404($from, $to);
        self::assertCount(1, $errors404);
        self::assertSame('/traffic-shapes', $errors404[0]['path']);
        self::assertSame(1, $errors404[0]['hits']);
        self::assertSame($occurredAt->format('Y-m-d H:i:s'), $errors404[0]['lastSeen']->format('Y-m-d H:i:s'));

        self::assertSame([[
            'period' => '2026-06-20',
            'views' => 1,
            'visitors' => 1,
        ]], $repository->trafficByDay($from, $to));
        self::assertSame([[
            'period' => '2026-06-20 10:00',
            'views' => 1,
            'visitors' => 1,
        ]], $repository->trafficByHour($from, $to));
    }
}
