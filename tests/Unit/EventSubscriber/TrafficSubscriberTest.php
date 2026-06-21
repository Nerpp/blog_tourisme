<?php

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\TrafficEvent;
use App\EventSubscriber\TrafficSubscriber;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use App\Service\Traffic\TrafficContentResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TrafficSubscriberTest extends TestCase
{
    public function testPublicHtmlRequestRecordsOnlyExpectedCoarseTrafficData(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 1);
        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/125.0 Safari/537.36';
        $request = Request::create(
            'https://blog.example.test/?utm_source=newsletter&utm_medium=email&utm_campaign=summer',
            'GET',
            server: [
                'REMOTE_ADDR' => '203.0.113.42',
                'HTTP_USER_AGENT' => $userAgent,
                'HTTP_REFERER' => 'https://Partner.Example/path?private=value',
            ],
        );
        $request->attributes->set('_route', 'app_home');
        $request->attributes->set('page', 2);
        $request->attributes->set('object', new \stdClass());
        $request->attributes->set('_traffic_started_at', microtime(true) - 0.01);

        $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('GET', $event->getMethod());
        self::assertSame('/', $event->getPath());
        self::assertSame('app_home', $event->getRouteName());
        self::assertSame(['page' => 2], $event->getRouteParams());
        self::assertSame('home', $event->getContentType());
        self::assertSame('Accueil', $event->getContentTitle());
        self::assertSame(200, $event->getStatusCode());
        self::assertNotNull($event->getDurationMs());
        self::assertSame('partner.example', $event->getReferrerHost());
        self::assertSame('desktop', $event->getDeviceType());
        self::assertSame('Chrome', $event->getBrowserFamily());
        self::assertSame('Linux', $event->getOsFamily());
        self::assertFalse($event->isBot());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $event->getVisitorHash());
        self::assertSame('newsletter', $this->property($event, 'utmSource'));
        self::assertSame('email', $this->property($event, 'utmMedium'));
        self::assertSame('summer', $this->property($event, 'utmCampaign'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $this->property($event, 'userAgentHash'));
        self::assertStringNotContainsString('203.0.113.42', serialize($event));
        self::assertStringNotContainsString($userAgent, serialize($event));
    }

    public function testVisitorHashIsStableForSameCoarseNetworkAndChangesForAnotherNetwork(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 3);
        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/126.0';

        foreach (['203.0.10.4', '203.0.240.99', '198.51.100.8'] as $ip) {
            $request = Request::create('https://blog.example.test/', 'GET', server: [
                'REMOTE_ADDR' => $ip,
                'HTTP_USER_AGENT' => $userAgent,
            ]);
            $request->attributes->set('_route', 'app_home');
            $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));
        }

        self::assertCount(3, $events);
        self::assertSame($events[0]->getVisitorHash(), $events[1]->getVisitorHash());
        self::assertNotSame($events[0]->getVisitorHash(), $events[2]->getVisitorHash());
    }

    public function testAdminAndExplicitlyExcludedRoutesAreIgnored(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 0);

        $adminPath = Request::create('https://blog.example.test/admin/traffic');
        $adminPath->attributes->set('_route', 'admin_traffic_index');
        $subscriber->onTerminate($this->terminateEvent($adminPath, $this->htmlResponse()));

        $adminRoute = Request::create('https://blog.example.test/private-area');
        $adminRoute->attributes->set('_route', 'admin_dashboard');
        $subscriber->onTerminate($this->terminateEvent($adminRoute, $this->htmlResponse()));

        $login = Request::create('https://blog.example.test/login');
        $login->attributes->set('_route', 'app_login');
        $subscriber->onTerminate($this->terminateEvent($login, $this->htmlResponse()));

        self::assertSame([], $events);
    }

    public function testAssetsAndNonHtmlResponsesAreIgnored(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 0);

        $asset = Request::create('https://blog.example.test/build/app.js');
        $subscriber->onTerminate($this->terminateEvent(
            $asset,
            new Response('console.log("test")', 200, ['content-type' => 'application/javascript']),
        ));

        $json = Request::create('https://blog.example.test/api/data');
        $json->attributes->set('_route', 'app_api_data');
        $subscriber->onTerminate($this->terminateEvent(
            $json,
            new Response('{}', 200, ['content-type' => 'application/json']),
        ));

        self::assertSame([], $events);
    }

    public function testNonGetAndPrefetchRequestsAreIgnored(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 0);

        $post = Request::create('https://blog.example.test/', 'POST');
        $post->attributes->set('_route', 'app_home');
        $subscriber->onTerminate($this->terminateEvent($post, $this->htmlResponse()));

        $prefetch = Request::create('https://blog.example.test/', 'GET', server: [
            'HTTP_PURPOSE' => 'prefetch',
        ]);
        $prefetch->attributes->set('_route', 'app_home');
        $subscriber->onTerminate($this->terminateEvent($prefetch, $this->htmlResponse()));

        self::assertSame([], $events);
    }

    public function testNotFoundHtmlIsTrackedButServerErrorIsIgnored(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 1);

        $notFound = Request::create('https://blog.example.test/missing-page');
        $subscriber->onTerminate($this->terminateEvent($notFound, $this->htmlResponse(404)));

        $serverError = Request::create('https://blog.example.test/broken-page');
        $subscriber->onTerminate($this->terminateEvent($serverError, $this->htmlResponse(500)));

        self::assertCount(1, $events);
        self::assertSame('/missing-page', $events[0]->getPath());
        self::assertSame(404, $events[0]->getStatusCode());
        self::assertSame('error', $events[0]->getContentType());
    }

    /**
     * @param list<TrafficEvent> $events
     */
    private function subscriber(array &$events, int $expectedWrites): TrafficSubscriber
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly($expectedWrites))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$events): void {
                self::assertInstanceOf(TrafficEvent::class, $entity);
                $events[] = $entity;
            });
        $entityManager
            ->expects(self::exactly($expectedWrites))
            ->method('flush');

        return new TrafficSubscriber(
            $entityManager,
            new TrafficContentResolver(
                $this->createStub(ArticleRepository::class),
                $this->createStub(HikeDraftRepository::class),
                $this->createStub(CityVisitDraftRepository::class),
                $this->createStub(DestinationRepository::class),
                $this->createStub(PlaceRepository::class),
            ),
            new ParameterBag(['kernel.secret' => 'unit-test-traffic-secret']),
            new NullLogger(),
        );
    }

    private function terminateEvent(Request $request, Response $response): TerminateEvent
    {
        return new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $response,
        );
    }

    private function htmlResponse(int $statusCode = 200): Response
    {
        return new Response('<html></html>', $statusCode, ['content-type' => 'text/html; charset=UTF-8']);
    }

    private function property(TrafficEvent $event, string $name): mixed
    {
        return (new \ReflectionProperty($event, $name))->getValue($event);
    }
}
