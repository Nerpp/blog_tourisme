<?php

namespace App\Tests\Unit\Seo;

use App\Service\Seo\PublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

final class PublicUrlGeneratorTest extends TestCase
{
    public function testCanonicalUsesTheConfiguredOriginAndRouteWithoutRequestQueryParameters(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('app_article_show', ['slug' => 'article-test'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/articles/article-test');
        $request = Request::create('http://untrusted.example/index.php/articles/article-test?utm_source=test');
        $request->attributes->set('_route', 'app_article_show');
        $request->attributes->set('_route_params', ['slug' => 'article-test']);

        $canonical = (new PublicUrlGenerator($router, 'https://estela-exploration.fr'))->canonicalForRequest($request);

        self::assertSame('https://estela-exploration.fr/articles/article-test', $canonical);
    }

    public function testRedirectCombinesHostSchemeAndFrontControllerNormalizationInOneHop(): void
    {
        $generator = new PublicUrlGenerator($this->createStub(RouterInterface::class), 'https://estela-exploration.fr');
        $request = Request::create('http://www.estela-exploration.fr/index.php/articles?source=newsletter');

        self::assertSame(
            'https://estela-exploration.fr/articles?source=newsletter',
            $generator->canonicalRedirectFor($request),
        );
        self::assertNull($generator->canonicalRedirectFor(Request::create('https://estela-exploration.fr/articles?source=newsletter')));
        self::assertNull($generator->canonicalRedirectFor(Request::create('http://localhost/articles')));
    }

    public function testItLocksTheRouterContextToTheConfiguredOriginWithoutAFrontController(): void
    {
        $context = new RequestContext();
        $context->setBaseUrl('/index.php');
        $context->setHost('untrusted.example');
        $context->setScheme('http');
        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn($context);

        (new PublicUrlGenerator($router, 'https://estela-exploration.fr'))->configureRouterContext();

        self::assertSame('https', $context->getScheme());
        self::assertSame('estela-exploration.fr', $context->getHost());
        self::assertSame('', $context->getBaseUrl());
        self::assertSame(443, $context->getHttpsPort());
    }

    public function testItRejectsAConfiguredPublicUrlContainingAPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PublicUrlGenerator($this->createStub(RouterInterface::class), 'https://estela-exploration.fr/public');
    }
}
