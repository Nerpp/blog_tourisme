<?php

namespace App\Tests\Unit\Weather;

use App\Service\Weather\QnhProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
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

    public function testItUsesNumericAltimAndObservationTimestampWhenRawTokenIsAbsent(): void
    {
        $timestamp = 1_760_000_000;
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                [
                    'icaoId' => 'LFMP',
                    'obsTime' => $timestamp,
                    'altim' => 29.92,
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame('metar', $result['source']);
        self::assertSame(1013, $result['qnhHpa']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $timestamp), $result['observedAt']);
        self::assertNull($result['raw']);
    }

    public function testItFallsBackToOpenMeteoWhenNearbyMetarReturnsNoContent(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse('', ['http_code' => 204]),
            new MockResponse(json_encode([
                'current' => [
                    'time' => '2026-06-05T12:15',
                    'pressure_msl' => 1008.7,
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame('open_meteo', $result['source']);
        self::assertSame(1009, $result['qnhHpa']);
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

    public function testItKeepsMetarQnhButIgnoresAnInvalidObservationDate(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                [
                    'icaoId' => 'LFMP',
                    'reportTime' => 'date-inanalysable',
                    'rawOb' => 'METAR LFMP 051200Z AUTO 31010KT 9999 Q1024',
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame(1024, $result['qnhHpa']);
        self::assertNull($result['observedAt']);
        self::assertStringEndsWith('heure inconnue', $result['summary']);
        self::assertStringNotContainsString('1970', $result['summary']);
    }

    public function testItKeepsOpenMeteoPressureButIgnoresAnInvalidObservationDate(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                'current' => [
                    'time' => 'date-inanalysable',
                    'pressure_msl' => 1016.2,
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(48.8566, 2.3522);

        self::assertTrue($result['ok']);
        self::assertSame(1016, $result['qnhHpa']);
        self::assertNull($result['observedAt']);
        self::assertStringEndsWith('heure inconnue', $result['summary']);
        self::assertStringNotContainsString('1970', $result['summary']);
    }

    public function testItReturnsUnavailableWhenMetarAndOpenMeteoDoNotProvidePressure(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse(json_encode([
                [
                    'icaoId' => 'LFMP',
                    'rawOb' => 'METAR LFMP NIL',
                ],
            ], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'current' => [
                    'time' => '2026-06-05T12:15',
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertFalse($result['ok']);
        self::assertSame('QNH indisponible pour cette position. Réessayez plus tard ou utilisez une source météo locale.', $result['message']);
    }

    public function testItFallsBackAfterMetarHttpErrorAndReturnsUnavailableAfterWeatherHttpError(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse('server error', ['http_code' => 503]),
            new MockResponse('server error', ['http_code' => 502]),
        ]), new ArrayAdapter());

        self::assertFalse($provider->provide(42.70, 2.80)['ok']);
    }

    public function testItHandlesInvalidJsonFromBothProviders(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse('{invalid'),
            new MockResponse('{invalid'),
        ]), new ArrayAdapter());

        self::assertFalse($provider->provide(42.70, 2.80)['ok']);
    }

    public function testItHandlesTransportFailuresFromBothProviders(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): never {
            ++$requests;

            throw new TransportException('network unavailable');
        });

        self::assertFalse((new QnhProvider($client, new ArrayAdapter()))->provide(42.70, 2.80)['ok']);
        self::assertSame(2, $requests);
    }

    public function testItAcceptsHectopascalAltimeterAndMissingObservationTime(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse('[{"altim":1021.6}]'),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame(1022, $result['qnhHpa']);
        self::assertNull($result['observedAt']);
        self::assertStringEndsWith('heure inconnue', $result['summary']);
    }

    public function testItFallsBackFromMalformedMetarReportToWeatherWithoutObservationTime(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse('[null]'),
            new MockResponse('{"current":{"pressure_msl":1014.4}}'),
        ]), new ArrayAdapter());

        $result = $provider->provide(42.70, 2.80);

        self::assertTrue($result['ok']);
        self::assertSame('open_meteo', $result['source']);
        self::assertSame(1014, $result['qnhHpa']);
        self::assertNull($result['observedAt']);
        self::assertStringEndsWith('heure inconnue', $result['summary']);
    }

    public function testItRejectsOutOfRangeAltimeterWithoutCrashing(): void
    {
        $provider = new QnhProvider(new MockHttpClient([
            new MockResponse('[{"altim":1200}]'),
            new MockResponse('{"current":{"pressure_msl":"invalid"}}'),
        ]), new ArrayAdapter());

        self::assertFalse($provider->provide(42.70, 2.80)['ok']);
    }

    public function testItReusesFreshCachedMetar(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('[{"rawOb":"METAR LFMP Q1018"}]');
        });
        $provider = new QnhProvider($client, new ArrayAdapter());

        self::assertSame(1018, $provider->provide(42.70, 2.80)['qnhHpa']);
        self::assertSame(1018, $provider->provide(42.70, 2.80)['qnhHpa']);
        self::assertSame(1, $requests);
    }

    public function testItRejectsInvalidCoordinates(): void
    {
        $provider = new QnhProvider(new MockHttpClient([]), new ArrayAdapter());

        $this->expectException(InvalidArgumentException::class);

        $provider->provide(120, 2.8);
    }

    public function testItRejectsInvalidLongitude(): void
    {
        $provider = new QnhProvider(new MockHttpClient([]), new ArrayAdapter());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Longitude invalide.');

        $provider->provide(42.7, 220);
    }
}
