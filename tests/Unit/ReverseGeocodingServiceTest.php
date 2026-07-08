<?php

namespace App\Tests\Unit;

use App\Service\ReverseGeocodingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReverseGeocodingServiceTest extends TestCase
{
    public function testItReturnsNormalizedLocationFromApiResponse(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://geo.api.gouv.fr/communes?', $url);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame('42.7', $query['lat']);
            self::assertSame('2.8', $query['lon']);
            self::assertSame('nom,code,departement,region', $query['fields']);

            return new MockResponse(json_encode([[
                'nom' => 'Perpignan',
                'code' => '66136',
                'departement' => ['nom' => 'Pyrénées-Orientales', 'code' => '66'],
                'region' => ['nom' => 'Occitanie', 'code' => '76'],
            ]], \JSON_THROW_ON_ERROR));
        });

        self::assertSame([
            'communeName' => 'Perpignan',
            'communeCode' => '66136',
            'departmentName' => 'Pyrénées-Orientales',
            'departmentCode' => '66',
            'regionName' => 'Occitanie',
            'regionCode' => '76',
        ], (new ReverseGeocodingService($client))->reverse(42.7, 2.8));
    }

    public function testItKeepsRequiredCommuneAndDefaultsIncompleteNestedData(): void
    {
        $service = new ReverseGeocodingService(new MockHttpClient([
            new MockResponse('{"0":{"nom":"Collioure","code":"66053","departement":null,"region":{"nom":42}}}'),
        ]));

        self::assertSame([
            'communeName' => 'Collioure',
            'communeCode' => '66053',
            'departmentName' => '',
            'departmentCode' => '',
            'regionName' => '',
            'regionCode' => '',
        ], $service->reverse(42.525, 3.083));
    }

    /** @param list<MockResponse> $responses */
    #[DataProvider('unusableResponseProvider')]
    public function testItReturnsNullForUnusableResponses(array $responses): void
    {
        self::assertNull((new ReverseGeocodingService(new MockHttpClient($responses)))->reverse(42.7, 2.8));
    }

    /** @return iterable<string, array{list<MockResponse>}> */
    public static function unusableResponseProvider(): iterable
    {
        yield 'empty list' => [[new MockResponse('[]')]];
        yield 'missing required commune code' => [[new MockResponse('[{"nom":"Perpignan"}]')]];
        yield 'wrong commune field types' => [[new MockResponse('[{"nom":42,"code":66136}]')]];
        yield 'invalid json' => [[new MockResponse('{invalid')]];
        yield 'http error' => [[new MockResponse('{"message":"failure"}', ['http_code' => 503])]];
    }

    public function testItReturnsNullOnTransportFailure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('network unavailable');
        });

        self::assertNull((new ReverseGeocodingService($client))->reverse(42.7, 2.8));
    }
}
