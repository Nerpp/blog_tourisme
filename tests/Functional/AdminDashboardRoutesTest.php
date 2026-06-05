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
        yield 'field tools gps' => ['/admin/outils-terrain/gps'];
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

    public function testVerifiedAdminCanUseGpsFieldToolPage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/outils-terrain/gps');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GPS terrain', $crawler->filter('h1')->text());
        self::assertStringContainsString('GPS haute précision', $crawler->filter('[data-gps-start]')->text());
        self::assertGreaterThan(0, $crawler->filter('[data-high-precision-gps]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-latitude]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-longitude]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-accuracy]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-status]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-coordinates]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-stop]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-copy]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-qnh-tool]')->count());
        self::assertStringContainsString('Afficher le QNH', $crawler->filter('[data-qnh-fetch]')->text());
        self::assertGreaterThan(0, $crawler->filter('[data-qnh-result]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-qnh-copy-value]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-qnh-copy-summary]')->count());
    }

    public function testAdminMenuContainsGpsFieldToolEntry(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/studio');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('nav.admin-nav a[href="/admin/outils-terrain/gps"]')->count());
        self::assertStringContainsString('GPS terrain', $crawler->filter('nav.admin-nav')->text());
    }

    public function testAnonymousVisitorIsRedirectedFromQnhEndpoint(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/outils-terrain/qnh?latitude=42.7&longitude=2.8');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromQnhEndpoint(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/outils-terrain/qnh?latitude=42.7&longitude=2.8');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminGetsBadRequestForInvalidQnhCoordinates(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/outils-terrain/qnh?latitude=abc&longitude=2.8');

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"ok":false,"message":"Latitude et longitude valides sont obligatoires."}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
