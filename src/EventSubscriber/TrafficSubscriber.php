<?php

namespace App\EventSubscriber;

use App\Entity\TrafficEvent;
use App\Service\Traffic\TrafficContentResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TrafficSubscriber implements EventSubscriberInterface
{
    private const START_ATTRIBUTE = '_traffic_started_at';

    /** @var list<string> */
    private const EXCLUDED_PREFIXES = [
        '/admin',
        '/_profiler',
        '/_wdt',
        '/assets',
        '/build',
        '/uploads',
        '/images',
    ];

    /** @var list<string> */
    private const EXCLUDED_ROUTES = [
        'app_login',
        'app_logout',
        'app_register',
        'app_profile',
        'app_article_comment_create',
        'app_place_comment_create',
        'app_comment_edit',
        'app_comment_delete',
        'app_comment_report',
        'app_gps_point_open',
        'app_gps_start_open',
        'app_gps_route_open',
    ];

    /** @var list<string> */
    private const BOT_SIGNATURES = [
        'googlebot',
        'bingbot',
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'ahrefsbot',
        'semrushbot',
        'mj12bot',
        'dotbot',
        'petalbot',
        'crawler',
        'spider',
        'bot/',
        'bot ',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrafficContentResolver $contentResolver,
        private readonly ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 255],
            KernelEvents::TERMINATE => ['onTerminate', -255],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::START_ATTRIBUTE, microtime(true));
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->shouldTrack($request, $response)) {
            return;
        }

        try {
            $this->record($request, $response);
        } catch (\Throwable $exception) {
            $this->logger->warning('Impossible d’enregistrer un événement trafic.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (!$request->isMethod('GET') || $this->isPrefetch($request)) {
            return false;
        }

        $path = $request->getPathInfo();
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        if (preg_match('/\.(?:css|js|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|pdf|zip)$/i', $path) === 1) {
            return false;
        }

        $route = $request->attributes->get('_route');
        if (is_string($route) && (str_starts_with($route, 'admin_') || in_array($route, self::EXCLUDED_ROUTES, true))) {
            return false;
        }

        if (!in_array($response->getStatusCode(), [200, 301, 302, 404], true)) {
            return false;
        }

        $contentType = (string) $response->headers->get('content-type', '');

        return str_contains($contentType, 'text/html') || $contentType === '';
    }

    private function record(Request $request, Response $response): void
    {
        $now = new \DateTimeImmutable();
        $route = $request->attributes->get('_route');
        $userAgent = (string) $request->headers->get('user-agent', '');
        $isBot = $this->isBot($userAgent);
        $content = $this->contentResolver->resolve($request);
        $start = $request->attributes->get(self::START_ATTRIBUTE);
        $durationMs = is_float($start) ? (int) round((microtime(true) - $start) * 1000) : null;

        // Privacy note: the raw IP and raw user-agent are used only in memory to derive coarse,
        // rotating hashes/families. They are never persisted.
        $event = (new TrafficEvent())
            ->setOccurredAt($now)
            ->setMethod($request->getMethod())
            ->setPath($request->getPathInfo())
            ->setRouteName(is_string($route) ? $route : null)
            ->setRouteParams($this->routeParams($request))
            ->setContentType($content['contentType'])
            ->setContentId($content['contentId'])
            ->setContentTitle($content['contentTitle'])
            ->setStatusCode($response->getStatusCode())
            ->setDurationMs($durationMs)
            ->setReferrerHost($this->referrerHost($request))
            ->setUtmSource($this->nullableQuery($request, 'utm_source'))
            ->setUtmMedium($this->nullableQuery($request, 'utm_medium'))
            ->setUtmCampaign($this->nullableQuery($request, 'utm_campaign'))
            ->setDeviceType($this->deviceType($userAgent, $isBot))
            ->setBrowserFamily($this->browserFamily($userAgent, $isBot))
            ->setOsFamily($this->osFamily($userAgent, $isBot))
            ->setVisitorHash($this->visitorHash($request, $now))
            ->setIsBot($isBot)
            ->setUserAgentHash($userAgent !== '' ? hash_hmac('sha256', $this->browserFamily($userAgent, $isBot).'|'.$this->osFamily($userAgent, $isBot), $this->secret()) : null);

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    /** @return array<string, scalar|null>|null */
    private function routeParams(Request $request): ?array
    {
        $params = [];
        foreach ($request->attributes->all() as $key => $value) {
            if (str_starts_with($key, '_') || !is_scalar($value)) {
                continue;
            }

            $params[$key] = $value;
        }

        return $params === [] ? null : $params;
    }

    private function visitorHash(Request $request, \DateTimeImmutable $date): ?string
    {
        $clientIp = (string) $request->getClientIp();
        if ($clientIp === '') {
            return null;
        }

        return hash_hmac('sha256', implode('|', [
            $date->format('Y-m-d'),
            $this->coarseIp($clientIp),
            $this->browserFamily((string) $request->headers->get('user-agent', ''), false),
            $this->osFamily((string) $request->headers->get('user-agent', ''), false),
        ]), $this->secret());
    }

    private function coarseIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return sprintf('%s.%s.0.0', $parts[0] ?? '0', $parts[1] ?? '0');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 4)).'::';
        }

        return 'unknown';
    }

    private function referrerHost(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer', '');
        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || $host === $request->getHost()) {
            return null;
        }

        return mb_strtolower($host);
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return $value === '' ? null : $value;
    }

    private function isBot(string $userAgent): bool
    {
        $ua = mb_strtolower($userAgent);
        foreach (self::BOT_SIGNATURES as $signature) {
            if (str_contains($ua, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function deviceType(string $userAgent, bool $isBot): string
    {
        $ua = mb_strtolower($userAgent);
        if ($isBot) {
            return 'bot';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }
        if ($ua !== '') {
            return 'desktop';
        }

        return 'unknown';
    }

    private function browserFamily(string $userAgent, bool $isBot): string
    {
        $ua = mb_strtolower($userAgent);
        if ($isBot) {
            return 'Bot';
        }
        if (str_contains($ua, 'firefox')) {
            return 'Firefox';
        }
        if (str_contains($ua, 'edg/')) {
            return 'Edge';
        }
        if (str_contains($ua, 'chrome') || str_contains($ua, 'chromium')) {
            return 'Chrome';
        }
        if (str_contains($ua, 'safari')) {
            return 'Safari';
        }

        return $ua === '' ? 'unknown' : 'Other';
    }

    private function osFamily(string $userAgent, bool $isBot): string
    {
        $ua = mb_strtolower($userAgent);
        if ($isBot) {
            return 'Bot';
        }
        if (str_contains($ua, 'windows')) {
            return 'Windows';
        }
        if (str_contains($ua, 'android')) {
            return 'Android';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'mac os')) {
            return 'Apple';
        }
        if (str_contains($ua, 'linux')) {
            return 'Linux';
        }

        return $ua === '' ? 'unknown' : 'Other';
    }

    private function isPrefetch(Request $request): bool
    {
        return in_array(mb_strtolower((string) $request->headers->get('purpose')), ['prefetch', 'preview'], true)
            || in_array(mb_strtolower((string) $request->headers->get('sec-purpose')), ['prefetch', 'preview'], true)
            || mb_strtolower((string) $request->headers->get('x-moz')) === 'prefetch';
    }

    private function secret(): string
    {
        return (string) $this->parameterBag->get('kernel.secret');
    }
}
