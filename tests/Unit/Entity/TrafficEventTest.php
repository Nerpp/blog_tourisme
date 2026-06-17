<?php

namespace App\Tests\Unit\Entity;

use App\Entity\TrafficEvent;
use PHPUnit\Framework\TestCase;

final class TrafficEventTest extends TestCase
{
    public function testDefaultsAndSettersNormalizeStoredTrafficData(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-06-17 12:00:00');
        $event = (new TrafficEvent())
            ->setOccurredAt($occurredAt)
            ->setMethod('post-with-too-long-name')
            ->setPath('/'.str_repeat('p', 600))
            ->setRouteName(str_repeat('route', 40))
            ->setRouteParams(['slug' => 'article-test', 'page' => 2, 'empty' => null])
            ->setContentType(str_repeat('content', 10))
            ->setContentId(42)
            ->setContentTitle(str_repeat('Titre ', 80))
            ->setStatusCode(404)
            ->setDurationMs(123)
            ->setReferrerHost(str_repeat('referrer', 60))
            ->setDeviceType('mobile')
            ->setBrowserFamily('Firefox')
            ->setOsFamily('Linux')
            ->setVisitorHash('visitor-hash')
            ->setIsBot(true)
            ->setUtmSource('newsletter')
            ->setUtmMedium('email')
            ->setUtmCampaign('summer')
            ->setUserAgentHash('ua-hash');

        self::assertNull($event->getId());
        self::assertSame($occurredAt, $event->getOccurredAt());
        self::assertSame('POST-WITH-', $event->getMethod());
        self::assertSame(500, mb_strlen($event->getPath()));
        self::assertSame(120, mb_strlen((string) $event->getRouteName()));
        self::assertSame(['slug' => 'article-test', 'page' => 2, 'empty' => null], $event->getRouteParams());
        self::assertSame(40, mb_strlen((string) $event->getContentType()));
        self::assertSame(42, $event->getContentId());
        self::assertSame(255, mb_strlen((string) $event->getContentTitle()));
        self::assertSame(404, $event->getStatusCode());
        self::assertSame(123, $event->getDurationMs());
        self::assertSame(255, mb_strlen((string) $event->getReferrerHost()));
        self::assertSame('mobile', $event->getDeviceType());
        self::assertSame('Firefox', $event->getBrowserFamily());
        self::assertSame('Linux', $event->getOsFamily());
        self::assertSame('visitor-hash', $event->getVisitorHash());
        self::assertTrue($event->isBot());
    }

    public function testNullableFieldsCanBeCleared(): void
    {
        $event = (new TrafficEvent())
            ->setRouteName('app_home')
            ->setRouteParams(['foo' => 'bar'])
            ->setContentType('article')
            ->setContentId(12)
            ->setContentTitle('Title')
            ->setDurationMs(10)
            ->setReferrerHost('example.test')
            ->setDeviceType('desktop')
            ->setBrowserFamily('Chrome')
            ->setOsFamily('macOS')
            ->setVisitorHash('visitor')
            ->setIsBot(false);

        $event
            ->setRouteName(null)
            ->setRouteParams(null)
            ->setContentType(null)
            ->setContentId(null)
            ->setContentTitle(null)
            ->setDurationMs(null)
            ->setReferrerHost(null)
            ->setDeviceType(null)
            ->setBrowserFamily(null)
            ->setOsFamily(null)
            ->setVisitorHash(null);

        self::assertNull($event->getRouteName());
        self::assertNull($event->getRouteParams());
        self::assertNull($event->getContentType());
        self::assertNull($event->getContentId());
        self::assertNull($event->getContentTitle());
        self::assertNull($event->getDurationMs());
        self::assertNull($event->getReferrerHost());
        self::assertNull($event->getDeviceType());
        self::assertNull($event->getBrowserFamily());
        self::assertNull($event->getOsFamily());
        self::assertNull($event->getVisitorHash());
        self::assertFalse($event->isBot());
    }
}
