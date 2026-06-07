<?php

namespace App\Tests\Functional;

use App\Service\Weather\QnhProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        yield 'prevision destinations' => ['/admin/previsions/destinations'];
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
        self::assertGreaterThan(0, $crawler->filter('[data-gps-altitude]')->count());
        self::assertStringContainsString('Hauteur / altitude GPS', $crawler->text());
        self::assertStringContainsString('Altitude manuelle pour le QFE', $crawler->text());
        self::assertStringContainsString('Meilleure position GPS conservée temporairement', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Enregistrez pour la garder', (string) $client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-status]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-coordinates]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-stop]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-gps-copy]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-qnh-tool]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-high-precision-gps] [data-qnh-tool]')->count());
        self::assertStringContainsString('Afficher le QNH / QFE', $crawler->filter('[data-qnh-fetch]')->text());
        self::assertStringContainsString('QFE conseillé Skywatch page 6', $crawler->text());
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

    public function testVerifiedAdminGetsQfeWhenAltitudeIsProvided(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $this->mockQnhProvider(1016);

        $client->request('GET', '/admin/outils-terrain/qnh?latitude=42.7&longitude=2.8&altitude=100');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"ok":true,"source":"metar","qnhHpa":1016,"label":"QNH conseillé","station":{"icao":"LFMP","name":"Perpignan-Rivesaltes","distanceKm":7.3},"observedAt":"2026-06-05T12:00:00.000Z","raw":"METAR LFMP 051200Z AUTO 31010KT 9999 Q1016","message":"QNH récupéré et QFE calculé à partir de l’altitude utilisée.","reliability":"METAR station proche","summary":"QNH 1016 hPa - Altitude 100 m - QFE conseillé Skywatch page 6 : 1004 hPa","altitudeMeters":100,"altitudeSource":"gps","qfeHpa":1004}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testVerifiedAdminGetsQfeWithManualAltitudeSource(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $this->mockQnhProvider(1016);

        $client->request('GET', '/admin/outils-terrain/qnh?latitude=42.7&longitude=2.8&altitude=100&altitudeSource=manual');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"ok":true,"source":"metar","qnhHpa":1016,"label":"QNH conseillé","station":{"icao":"LFMP","name":"Perpignan-Rivesaltes","distanceKm":7.3},"observedAt":"2026-06-05T12:00:00.000Z","raw":"METAR LFMP 051200Z AUTO 31010KT 9999 Q1016","message":"QNH récupéré et QFE calculé à partir de l’altitude utilisée.","reliability":"METAR station proche","summary":"QNH 1016 hPa - Altitude 100 m - QFE conseillé Skywatch page 6 : 1004 hPa","altitudeMeters":100,"altitudeSource":"manual","qfeHpa":1004}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testVerifiedAdminGetsNullQfeWhenAltitudeIsMissing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $this->mockQnhProvider(1016);

        $client->request('GET', '/admin/outils-terrain/qnh?latitude=42.7&longitude=2.8');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"ok":true,"source":"metar","qnhHpa":1016,"label":"QNH conseillé","station":{"icao":"LFMP","name":"Perpignan-Rivesaltes","distanceKm":7.3},"observedAt":"2026-06-05T12:00:00.000Z","raw":"METAR LFMP 051200Z AUTO 31010KT 9999 Q1016","message":"QNH récupéré. Hauteur GPS indisponible : QFE non calculable automatiquement.","reliability":"METAR station proche","summary":"QNH 1016 hPa - Altitude indisponible - QFE non calculable","altitudeMeters":null,"altitudeSource":null,"qfeHpa":null}',
            (string) $client->getResponse()->getContent(),
        );
    }

    private function mockQnhProvider(int $qnhHpa): void
    {
        static::getContainer()->set(QnhProvider::class, new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                [
                    'icaoId' => 'LFMP',
                    'reportTime' => '2026-06-05T12:00:00.000Z',
                    'altim' => $qnhHpa,
                    'rawOb' => sprintf('METAR LFMP 051200Z AUTO 31010KT 9999 Q%d', $qnhHpa),
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter()));
    }
}
