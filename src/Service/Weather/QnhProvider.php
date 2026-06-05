<?php

namespace App\Service\Weather;

use InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class QnhProvider
{
    private const AVIATION_WEATHER_METAR_URL = 'https://aviationweather.gov/api/data/metar';
    private const OPEN_METEO_URL = 'https://api.open-meteo.com/v1/forecast';
    private const CACHE_TTL_SECONDS = 600;
    private const MAX_METAR_DISTANCE_KM = 100.0;

    /**
     * @var array<string, array{name: string, latitude: float, longitude: float}>
     */
    private const STATIONS = [
        'LFMP' => ['name' => 'Perpignan-Rivesaltes', 'latitude' => 42.7414, 'longitude' => 2.8693],
        'LFMK' => ['name' => 'Carcassonne-Salvaza', 'latitude' => 43.2161, 'longitude' => 2.3067],
        'LFMU' => ['name' => 'Béziers-Vias', 'latitude' => 43.3234, 'longitude' => 3.3536],
        'LFMT' => ['name' => 'Montpellier-Méditerranée', 'latitude' => 43.5798, 'longitude' => 3.9608],
        'LFBO' => ['name' => 'Toulouse-Blagnac', 'latitude' => 43.6287, 'longitude' => 1.3643],
        'LFML' => ['name' => 'Marseille-Provence', 'latitude' => 43.4359, 'longitude' => 5.2156],
        'LFMN' => ['name' => 'Nice-Côte d’Azur', 'latitude' => 43.6579, 'longitude' => 7.2122],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     source?: string,
     *     qnhHpa?: int,
     *     label?: string,
     *     station?: array{icao: string, name: string, distanceKm: float}|null,
     *     observedAt?: string|null,
     *     raw?: string|null,
     *     message: string,
     *     reliability?: string,
     *     summary?: string
     * }
     */
    public function provide(float $latitude, float $longitude): array
    {
        $this->assertCoordinates($latitude, $longitude);

        $nearestStation = $this->nearestStation($latitude, $longitude);
        if ($nearestStation['distanceKm'] <= self::MAX_METAR_DISTANCE_KM) {
            $metar = $this->metarQnh($nearestStation);
            if ($metar !== null) {
                return $metar;
            }
        }

        $openMeteo = $this->openMeteoQnh($latitude, $longitude);
        if ($openMeteo !== null) {
            return $openMeteo;
        }

        return [
            'ok' => false,
            'message' => 'QNH indisponible pour cette position. Réessayez plus tard ou utilisez une source météo locale.',
        ];
    }

    private function assertCoordinates(float $latitude, float $longitude): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('Latitude invalide.');
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Longitude invalide.');
        }
    }

    /**
     * @return array{icao: string, name: string, latitude: float, longitude: float, distanceKm: float}
     */
    private function nearestStation(float $latitude, float $longitude): array
    {
        $nearest = null;

        foreach (self::STATIONS as $icao => $station) {
            $distanceKm = $this->distanceKm($latitude, $longitude, $station['latitude'], $station['longitude']);
            if ($nearest === null || $distanceKm < $nearest['distanceKm']) {
                $nearest = [
                    'icao' => $icao,
                    'name' => $station['name'],
                    'latitude' => $station['latitude'],
                    'longitude' => $station['longitude'],
                    'distanceKm' => $distanceKm,
                ];
            }
        }

        return $nearest;
    }

    /**
     * @param array{icao: string, name: string, latitude: float, longitude: float, distanceKm: float} $station
     *
     * @return array{
     *     ok: bool,
     *     source: string,
     *     qnhHpa: int,
     *     label: string,
     *     station: array{icao: string, name: string, distanceKm: float},
     *     observedAt: string|null,
     *     raw: string|null,
     *     message: string,
     *     reliability: string,
     *     summary: string
     * }|null
     */
    private function metarQnh(array $station): ?array
    {
        $cacheKey = strtolower('qnh_metar_'.$station['icao']);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($station): ?array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                $response = $this->httpClient->request('GET', self::AVIATION_WEATHER_METAR_URL, [
                    'query' => [
                        'ids' => $station['icao'],
                        'format' => 'json',
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BlogTourisme/field-tools-qnh',
                    ],
                    'timeout' => 5,
                ]);

                if ($response->getStatusCode() === 204) {
                    return null;
                }

                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    return null;
                }

                $data = $response->toArray(false);
            } catch (ExceptionInterface) {
                return null;
            }

            $report = $data[0] ?? null;
            if (!is_array($report)) {
                return null;
            }

            $raw = is_string($report['rawOb'] ?? null) ? $report['rawOb'] : null;
            $qnh = $this->qnhFromReport($report, $raw);
            if ($qnh === null) {
                return null;
            }

            $observedAt = $this->observedAt($report);
            $distanceKm = round($station['distanceKm'], 1);
            $summary = sprintf(
                'QNH %d hPa - %s %s - %s',
                $qnh,
                $station['icao'],
                $station['name'],
                $observedAt !== null ? gmdate('H:i \U\T\C', strtotime($observedAt)) : 'heure inconnue',
            );

            return [
                'ok' => true,
                'source' => 'metar',
                'qnhHpa' => $qnh,
                'label' => 'QNH conseillé',
                'station' => [
                    'icao' => $station['icao'],
                    'name' => $station['name'],
                    'distanceKm' => $distanceKm,
                ],
                'observedAt' => $observedAt,
                'raw' => $raw,
                'message' => sprintf('QNH METAR récupéré depuis %s.', $station['icao']),
                'reliability' => 'METAR station proche',
                'summary' => $summary,
            ];
        });
    }

    /**
     * @return array{
     *     ok: bool,
     *     source: string,
     *     qnhHpa: int,
     *     label: string,
     *     station: null,
     *     observedAt: string|null,
     *     raw: null,
     *     message: string,
     *     reliability: string,
     *     summary: string
     * }|null
     */
    private function openMeteoQnh(float $latitude, float $longitude): ?array
    {
        $cacheKey = sprintf('qnh_open_meteo_%s_%s', round($latitude, 2), round($longitude, 2));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($latitude, $longitude): ?array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                $response = $this->httpClient->request('GET', self::OPEN_METEO_URL, [
                    'query' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'current' => 'pressure_msl,surface_pressure',
                        'timezone' => 'UTC',
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BlogTourisme/field-tools-qnh',
                    ],
                    'timeout' => 5,
                ]);

                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    return null;
                }

                $data = $response->toArray(false);
            } catch (ExceptionInterface) {
                return null;
            }

            $current = $data['current'] ?? null;
            if (!is_array($current) || !is_numeric($current['pressure_msl'] ?? null)) {
                return null;
            }

            $qnh = (int) round((float) $current['pressure_msl']);
            $observedAt = is_string($current['time'] ?? null) ? $current['time'].'Z' : null;

            return [
                'ok' => true,
                'source' => 'open_meteo',
                'qnhHpa' => $qnh,
                'label' => 'QNH estimé',
                'station' => null,
                'observedAt' => $observedAt,
                'raw' => null,
                'message' => 'Estimation météo Open-Meteo pressure_msl.',
                'reliability' => 'Estimation météo, pas METAR station',
                'summary' => sprintf(
                    'QNH estimé %d hPa - Open-Meteo pressure_msl - %s',
                    $qnh,
                    $observedAt !== null ? gmdate('H:i \U\T\C', strtotime($observedAt)) : 'heure inconnue',
                ),
            ];
        });
    }

    /**
     * @param array<string, mixed> $report
     */
    private function qnhFromReport(array $report, ?string $raw): ?int
    {
        if ($raw !== null && preg_match('/\bQ(\d{4})\b/', $raw, $match) === 1) {
            return (int) $match[1];
        }

        if ($raw !== null && preg_match('/\bA(\d{4})\b/', $raw, $match) === 1) {
            return (int) round(((float) $match[1] / 100) * 33.8638866667);
        }

        if (!is_numeric($report['altim'] ?? null)) {
            return null;
        }

        $altimeter = (float) $report['altim'];
        if ($altimeter >= 800 && $altimeter <= 1100) {
            return (int) round($altimeter);
        }

        if ($altimeter >= 25 && $altimeter <= 32) {
            return (int) round($altimeter * 33.8638866667);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function observedAt(array $report): ?string
    {
        if (is_string($report['reportTime'] ?? null)) {
            return $report['reportTime'];
        }

        if (is_int($report['obsTime'] ?? null)) {
            return gmdate('Y-m-d\TH:i:s\Z', $report['obsTime']);
        }

        return null;
    }

    private function distanceKm(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($latB - $latA);
        $lonDelta = deg2rad($lonB - $lonA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
