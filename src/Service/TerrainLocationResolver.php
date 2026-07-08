<?php

namespace App\Service;

final class TerrainLocationResolver
{
    public function __construct(
        private readonly ReverseGeocodingService $reverseGeocodingService,
    ) {
    }

    /**
     * @return array{
     *     geocoding: array{
     *         communeName: string,
     *         communeCode: string,
     *         departmentName: string,
     *         departmentCode: string,
     *         regionName: string,
     *         regionCode: string
     *     }|null
     * }
     */
    public function resolve(float $latitude, float $longitude): array
    {
        $geocoding = $this->reverseGeocodingService->reverse($latitude, $longitude);

        return [
            'geocoding' => $geocoding,
        ];
    }
}
