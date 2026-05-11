<?php

namespace App\Service;

final class ReverseGeocodingService
{
    private const API_URL = 'https://geo.api.gouv.fr/communes';

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
        $query = http_build_query([
            'lat' => (string) $latitude,
            'lon' => (string) $longitude,
            'fields' => 'nom,code,departement,region',
            'format' => 'json',
            'geometry' => 'centre',
        ], '', '&', \PHP_QUERY_RFC3986);

        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'User-Agent: BlogTourisme/quick-hike-reverse-geocoding',
                ]),
            ],
        ]);

        $response = @file_get_contents(self::API_URL.'?'.$query, false, $context);
        if (!\is_string($response) || '' === $response || !$this->isSuccessfulResponse($http_response_header ?? [])) {
            return null;
        }

        try {
            $data = json_decode($response, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !isset($data[0]) || !\is_array($data[0])) {
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

    /** @param list<string> $headers */
    private function isSuccessfulResponse(array $headers): bool
    {
        if ([] === $headers) {
            return true;
        }

        return isset($headers[0]) && str_contains($headers[0], ' 200 ');
    }
}
