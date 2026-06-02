<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

final class PublicPagesTest extends WebTestCase
{
    public function testHomePageIsReachable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testLoginPageIsReachable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
    }

    public function testRegisterPageIsReachable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
    }

    public function testLogoutRouteAllowsOnlyPost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame(['POST'], $router->getRouteCollection()->get('app_logout')?->getMethods());
    }
}
