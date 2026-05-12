<?php

namespace App\Service;

use App\Entity\Destination;
use App\Enum\DestinationType;
use App\Repository\DestinationRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

final class TerrainLocationResolver
{
    public function __construct(
        private readonly DestinationRepository $destinationRepository,
        private readonly ReverseGeocodingService $reverseGeocodingService,
        private readonly SluggerInterface $slugger,
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
     *     }|null,
     *     destination: Destination|null
     * }
     */
    public function resolve(float $latitude, float $longitude): array
    {
        $geocoding = $this->reverseGeocodingService->reverse($latitude, $longitude);

        return [
            'geocoding' => $geocoding,
            'destination' => null !== $geocoding ? $this->findDestinationForCommune($geocoding['communeCode'], $geocoding['communeName']) : null,
        ];
    }

    public function findDestinationForCommune(string $communeCode, string $communeName): ?Destination
    {
        if ('' !== $communeCode) {
            $destination = $this->destinationRepository->findOneBy([
                'code' => $communeCode,
                'type' => DestinationType::City,
            ]) ?? $this->destinationRepository->findOneBy([
                'code' => $communeCode,
                'type' => DestinationType::Area,
            ]);

            if ($destination instanceof Destination) {
                return $destination;
            }
        }

        if ('' === $communeName) {
            return null;
        }

        $slug = strtolower((string) $this->slugger->slug($communeName));

        return $this->destinationRepository->findOneBy(['slug' => $slug, 'type' => DestinationType::City])
            ?? $this->destinationRepository->findOneBy(['slug' => $slug, 'type' => DestinationType::Area])
            ?? $this->destinationRepository->findOneBy(['name' => $communeName, 'type' => DestinationType::City])
            ?? $this->destinationRepository->findOneBy(['name' => $communeName, 'type' => DestinationType::Area]);
    }
}
