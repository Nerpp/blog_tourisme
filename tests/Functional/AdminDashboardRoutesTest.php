<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class AdminDashboardRoutesTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function adminDashboardRoutes(): iterable
    {
        yield 'quick hub' => ['/admin/quick'];
        yield 'studio hub' => ['/admin/studio'];
        yield 'traffic' => ['/admin/traffic'];
        yield 'comment reports' => ['/admin/comment-reports'];
        yield 'moderation keywords' => ['/admin/moderation-keywords'];
        yield 'field tools hikes' => ['/admin/field-tools/hikes'];
        yield 'field tools city visits' => ['/admin/field-tools/city-visits'];
    }

    #[DataProvider('adminDashboardRoutes')]
    public function testAnonymousVisitorIsRedirectedFromAdminDashboardRoute(string $path): void
    {
        $client = static::createClient();

        $client->request('GET', $path);

        self::assertResponseRedirects('/login');
    }

    #[DataProvider('adminDashboardRoutes')]
    public function testRegularUserIsRejectedFromAdminDashboardRoute(string $path): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', $path);

        self::assertResponseRedirects('/');
    }

    #[DataProvider('adminDashboardRoutes')]
    public function testUnverifiedAdminIsRejectedFromAdminDashboardRoute(string $path): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUnverifiedAdmin());

        $client->request('GET', $path);

        self::assertResponseRedirects('/');
    }

    #[DataProvider('adminDashboardRoutes')]
    public function testVerifiedAdminCanOpenAdminDashboardRoute(string $path): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    public function testFieldToolsIndexRedirectsToHikes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/field-tools');

        self::assertResponseRedirects('/admin/studio');
    }
}
