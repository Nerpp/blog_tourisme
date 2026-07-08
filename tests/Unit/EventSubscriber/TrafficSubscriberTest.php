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
use Psr\Log\LoggerInterface;
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
        $request->attributes->set('slug', 'private-route-slug');
        $request->attributes->set('_route_params', ['page' => 2, 'slug' => 'private-route-slug']);
        $request->attributes->set('object', new \stdClass());
        $request->attributes->set('_traffic_started_at', microtime(true) - 0.01);

        $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('GET', $event->getMethod());
        self::assertSame('/', $event->getPath());
        self::assertSame('app_home', $event->getRouteName());
        self::assertNull($event->getRouteParams());
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

    public function testIdentifiedBotIsIgnored(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 0);
        $request = Request::create('https://blog.example.test/', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]);
        $request->attributes->set('_route', 'app_home');

        $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));

        self::assertSame([], $events);
    }

    public function testAjaxHtmlRequestIsIgnored(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 0);
        $request = Request::create('https://blog.example.test/articles', 'GET', server: [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $request->attributes->set('_route', 'app_article_index');

        $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));

        self::assertTrue($request->isXmlHttpRequest());
        self::assertSame([], $events);
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

    public function testSubscribedEventsAndPrivacyClassificationsCoverCommonClients(): void
    {
        self::assertSame([
            'kernel.request' => ['onRequest', 255],
            'kernel.terminate' => ['onTerminate', -255],
        ], TrafficSubscriber::getSubscribedEvents());

        $events = [];
        $subscriber = $this->subscriber($events, 4);
        $requests = [
            Request::create('https://blog.example.test/ipad', server: [
                'REMOTE_ADDR' => '2001:db8:abcd:12::42',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Version/17.0 Safari/605.1.15',
            ]),
            Request::create('https://blog.example.test/android', server: [
                'REMOTE_ADDR' => 'not-an-ip',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; Mobile) AppleWebKit/537.36 Chrome/125.0',
            ]),
            Request::create('https://blog.example.test/windows', server: [
                'REMOTE_ADDR' => '198.51.100.42',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Edg/125.0',
            ]),
            Request::create('https://blog.example.test/anonymous', server: [
                'REMOTE_ADDR' => '',
                'HTTP_USER_AGENT' => '',
            ]),
        ];

        foreach ($requests as $request) {
            $request->attributes->set('_route', 'app_home');
            $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));
        }

        self::assertCount(4, $events);
        self::assertSame('tablet', $events[0]->getDeviceType());
        self::assertSame('Safari', $events[0]->getBrowserFamily());
        self::assertSame('Apple', $events[0]->getOsFamily());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $events[0]->getVisitorHash());
        self::assertSame('mobile', $events[1]->getDeviceType());
        self::assertSame('Android', $events[1]->getOsFamily());
        self::assertSame('Edge', $events[2]->getBrowserFamily());
        self::assertSame('Windows', $events[2]->getOsFamily());
        self::assertSame('unknown', $events[3]->getDeviceType());
        self::assertSame('unknown', $events[3]->getBrowserFamily());
        self::assertSame('unknown', $events[3]->getOsFamily());
        self::assertNull($events[3]->getVisitorHash());
    }

    public function testStaticExtensionIsIgnoredAndPersistenceFailureIsLogged(): void
    {
        $events = [];
        $subscriber = $this->subscriber($events, 0);
        $asset = Request::create('https://blog.example.test/document.pdf');
        $subscriber->onTerminate($this->terminateEvent($asset, $this->htmlResponse()));
        self::assertSame([], $events);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush')->willThrowException(new \RuntimeException('storage unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Impossible d’enregistrer un événement trafic.',
                self::callback(static fn (array $context): bool => $context['exception'] === \RuntimeException::class
                    && $context['message'] === 'storage unavailable'),
            );
        $subscriber = new TrafficSubscriber(
            $entityManager,
            new TrafficContentResolver(
                $this->createStub(ArticleRepository::class),
                $this->createStub(HikeDraftRepository::class),
                $this->createStub(CityVisitDraftRepository::class),
                $this->createStub(DestinationRepository::class),
                $this->createStub(PlaceRepository::class),
            ),
            new ParameterBag(['kernel.secret' => 'unit-test-traffic-secret']),
            $logger,
        );
        $request = Request::create('https://blog.example.test/failure');
        $request->attributes->set('_route', 'app_home');

        $subscriber->onTerminate($this->terminateEvent($request, $this->htmlResponse()));
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
