<?php

namespace App\Service\Seo;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final readonly class PublicUrlGenerator
{
    private string $origin;

    private string $scheme;

    private string $host;

    private ?int $port;

    public function __construct(
        private RouterInterface $router,
        string $publicBaseUrl,
    ) {
        $publicBaseUrl = rtrim(trim($publicBaseUrl), '/');
        $parts = parse_url($publicBaseUrl);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            throw new \InvalidArgumentException('APP_PUBLIC_URL must contain an HTTP(S) origin without a path, query string or fragment.');
        }

        $this->scheme = strtolower($parts['scheme']);
        $this->host = strtolower($parts['host']);
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->origin = $this->scheme.'://'.$this->host.$this->portSuffix();
    }

    /** @param array<string, mixed> $parameters */
    public function generate(string $route, array $parameters = []): string
    {
        return $this->absolute($this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH));
    }

    public function canonicalForRequest(Request $request): string
    {
        $route = $request->attributes->get('_route');
        $rawRouteParameters = $request->attributes->get('_route_params', []);
        $routeParameters = [];
        if (is_array($rawRouteParameters)) {
            foreach ($rawRouteParameters as $name => $value) {
                if (is_string($name)) {
                    $routeParameters[$name] = $value;
                }
            }
        }

        if (is_string($route) && $route !== '' && !str_starts_with($route, '_')) {
            try {
                return $this->generate($route, $routeParameters);
            } catch (RoutingExceptionInterface) {
                // Error pages and unmatched routes fall back to their normalized path.
            }
        }

        return $this->absolute($this->withoutFrontController($request->getPathInfo()));
    }

    public function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $this->scheme.':'.$url;
        }

        return $this->origin.'/'.ltrim($url, '/');
    }

    public function configureRouterContext(): void
    {
        $context = $this->router->getContext();
        $context->setScheme($this->scheme);
        $context->setHost($this->host);
        $context->setBaseUrl('');

        if ($this->scheme === 'https') {
            $context->setHttpsPort($this->port ?? 443);
        } else {
            $context->setHttpPort($this->port ?? 80);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withConfiguredRouterContext(callable $callback): mixed
    {
        $previousContext = clone $this->router->getContext();
        $this->configureRouterContext();

        try {
            return $callback();
        } finally {
            $this->router->setContext($previousContext);
        }
    }

    public function canonicalRedirectFor(Request $request): ?string
    {
        $requestHost = strtolower($request->getHost());
        $wwwHost = 'www.'.$this->host;
        if ($requestHost !== $this->host && $requestHost !== $wwwHost) {
            return null;
        }

        $requestUri = $request->server->getString('REQUEST_URI');
        $path = parse_url($requestUri !== '' ? $requestUri : $request->getRequestUri(), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $normalizedPath = $this->withoutFrontController($path);
        $hasFrontController = $normalizedPath !== $path;
        $forwardedProto = strtolower(trim(explode(',', $request->headers->get('X-Forwarded-Proto', ''))[0]));
        $isSecure = $request->isSecure() || $forwardedProto === 'https';

        if ($requestHost === $this->host && $isSecure && !$hasFrontController) {
            return null;
        }

        $queryString = $request->getQueryString();

        return $this->absolute($normalizedPath).($queryString !== null && $queryString !== '' ? '?'.$queryString : '');
    }

    private function withoutFrontController(string $path): string
    {
        $normalized = preg_replace('#^/index\.php(?=/|$)#i', '', $path);
        if (!is_string($normalized) || $normalized === '') {
            return '/';
        }

        return '/'.ltrim($normalized, '/');
    }

    private function portSuffix(): string
    {
        if ($this->port === null) {
            return '';
        }

        if (($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80)) {
            return '';
        }

        return ':'.$this->port;
    }
}
