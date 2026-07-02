<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReverseGeocodingService
{
    private const API_URL = 'https://geo.api.gouv.fr/communes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{
     *     communeName: string,
     *     communeCode: string,
     *     departmentName: string,
     *     departmentCode: string,
     *     regionName: string,
     *     regionCode: string
     * }|null
     */
    public function reverse(float $latitude, float $longitude): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'lat' => (string) $latitude,
                    'lon' => (string) $longitude,
                    'fields' => 'nom,code,departement,region',
                    'format' => 'json',
                    'geometry' => 'centre',
                ],
                'headers' => [
                    'Accept: application/json',
                    'User-Agent: BlogTourisme/quick-hike-reverse-geocoding',
                ],
                'timeout' => 4,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            $data = $response->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        if (!isset($data[0]) || !\is_array($data[0])) {
            return null;
        }

        $commune = $data[0];
        if (!isset($commune['nom'], $commune['code']) || !\is_string($commune['nom']) || !\is_string($commune['code'])) {
            return null;
        }

        $department = $commune['departement'] ?? [];
        $region = $commune['region'] ?? [];

        return [
            'communeName' => $commune['nom'],
            'communeCode' => $commune['code'],
            'departmentName' => \is_array($department) && \is_string($department['nom'] ?? null) ? $department['nom'] : '',
            'departmentCode' => \is_array($department) && \is_string($department['code'] ?? null) ? $department['code'] : '',
            'regionName' => \is_array($region) && \is_string($region['nom'] ?? null) ? $region['nom'] : '',
            'regionCode' => \is_array($region) && \is_string($region['code'] ?? null) ? $region['code'] : '',
        ];
    }
}
