<?php

namespace App\Tests\Unit\Weather;

use App\Service\Weather\QnhProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QnhProviderTest extends TestCase
{
    public function testItReturnsMetarQnhFromNearestStation(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                [
                    'icaoId' => 'LFMP',
                    'reportTime' => '2026-06-05T12:00:00.000Z',
                    'altim' => 1024,
                    'rawOb' => 'METAR LFMP 051200Z AUTO 31010KT 9999 BKN058 22/09 Q1024',
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame('metar', $result['source']);
        self::assertSame(1024, $result['qnhHpa']);
        self::assertSame('LFMP', $result['station']['icao']);
        self::assertSame('QNH 1024 hPa - LFMP Perpignan-Rivesaltes - 12:00 UTC', $result['summary']);
    }

    public function testItConvertsAmericanAltimeterWhenQnhTokenIsAbsent(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                [
                    'icaoId' => 'LFMP',
                    'reportTime' => '2026-06-05T12:00:00.000Z',
                    'rawOb' => 'METAR LFMP 051200Z AUTO 31010KT 9999 A3001',
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame(1016, $result['qnhHpa']);
    }

    public function testItFallsBackToOpenMeteoWhenNoMetarStationIsCloseEnough(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                'current' => [
                    'time' => '2026-06-05T12:15',
                    'pressure_msl' => 1016.2,
                    'surface_pressure' => 1009.4,
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(48.8566, 2.3522);

        self::assertTrue($result['ok']);
        self::assertSame('open_meteo', $result['source']);
        self::assertSame(1016, $result['qnhHpa']);
        self::assertNull($result['station']);
        self::assertSame('Estimation météo, pas METAR station', $result['reliability']);
    }

    public function testItRejectsInvalidCoordinates(): void
    {
        $provider = new QnhProvider(new MockHttpClient([]), new ArrayAdapter());

        $this->expectException(InvalidArgumentException::class);

        $provider->provide(120, 2.8);
    }
}
