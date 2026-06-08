<?php

namespace App\Service\Geography;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Enum\CityVisitPointType;
use App\Enum\HikePointType;
use App\Service\GeographicHierarchyResolver;
use Symfony\Component\HttpFoundation\Request;

final class LocationDraftHydrator
{
    public function __construct(
        private readonly GeographicHierarchyResolver $geographicHierarchyResolver,
    ) {
    }

    /**
     * @return array{
     *     communeName: string|null,
     *     communeInseeCode: string|null,
     *     postalCode: string|null,
     *     departmentName: string|null,
     *     departmentCode: string|null,
     *     regionName: string|null,
     *     country: string,
     *     communeCenterLatitude: float|null,
     *     communeCenterLongitude: float|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     gpsAccuracy: float|null
     * }
     */
    public function dataFromRequest(Request $request): array
    {
        $communeName = $this->firstRequestValue($request, ['locationCommune', 'detectedCommuneName', 'cityName', 'commune']);
        $country = $this->firstRequestValue($request, ['locationCountry', 'countryName', 'country']) ?? 'France';
        $centerLatitude = $this->coordinate(
            $this->firstRequestValue($request, ['communeCenterLatitude', 'locationCommuneCenterLatitude']),
            -90,
            90,
            'La latitude du centre de commune',
        );
        $centerLongitude = $this->coordinate(
            $this->firstRequestValue($request, ['communeCenterLongitude', 'locationCommuneCenterLongitude']),
            -180,
            180,
            'La longitude du centre de commune',
        );
        $latitude = $this->coordinate(
            $this->firstRequestValue($request, ['locationLatitude', 'latitude']),
            -90,
            90,
            'La latitude GPS',
        );
        $longitude = $this->coordinate(
            $this->firstRequestValue($request, ['locationLongitude', 'longitude']),
            -180,
            180,
            'La longitude GPS',
        );

        if (($centerLatitude === null) !== ($centerLongitude === null)) {
            throw new LocationDraftHydrationException('Le centre de commune doit contenir une latitude et une longitude valides.');
        }

        if (($latitude === null) !== ($longitude === null)) {
            throw new LocationDraftHydrationException('Le point GPS doit contenir une latitude et une longitude valides.');
        }

        return [
            'communeName' => $communeName,
            'communeInseeCode' => $this->firstRequestValue($request, ['locationInseeCode', 'detectedCommuneCode', 'code', 'inseeCode'], 20),
            'postalCode' => $this->firstRequestValue($request, ['locationPostalCode', 'postalCode'], 20),
            'departmentName' => $this->firstRequestValue($request, ['locationDepartment', 'detectedDepartmentName', 'departmentName', 'department']),
            'departmentCode' => $this->firstRequestValue($request, ['locationDepartmentCode', 'departmentCode'], 20),
            'regionName' => $this->firstRequestValue($request, ['locationRegion', 'detectedRegionName', 'regionName', 'region']),
            'country' => $country,
            'communeCenterLatitude' => $centerLatitude,
            'communeCenterLongitude' => $centerLongitude,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'gpsAccuracy' => $this->positiveFloat($this->firstRequestValue($request, ['locationAccuracy', 'gpsAccuracy', 'accuracy']), 'La précision GPS'),
        ];
    }

    /** @param array<string, mixed> $data */
    public function hydrateHikeDraft(HikeDraft $draft, array $data): void
    {
        $data = $this->normalizeData($data);
        $this->hydrateDetectedLocation($draft, $data);
        $draft->setGeographicDestination($this->resolveGeographicDestination($data));
        $this->syncHikePoint($draft, $data);
    }

    /** @param array<string, mixed> $data */
    public function hydrateCityVisitDraft(CityVisitDraft $draft, array $data): void
    {
        $data = $this->normalizeData($data);
        $this->hydrateDetectedLocation($draft, $data);
        $draft->setGeographicDestination($this->resolveGeographicDestination($data));
        $this->syncCityVisitPoint($draft, $data);
    }

    /** @param array<string, mixed> $data */
    private function normalizeData(array $data): array
    {
        $centerLatitude = $this->coordinate($this->value($data, ['communeCenterLatitude', 'centerLatitude']), -90, 90, 'La latitude du centre de commune');
        $centerLongitude = $this->coordinate($this->value($data, ['communeCenterLongitude', 'centerLongitude']), -180, 180, 'La longitude du centre de commune');
        $latitude = $this->coordinate($this->value($data, ['latitude', 'locationLatitude']), -90, 90, 'La latitude GPS');
        $longitude = $this->coordinate($this->value($data, ['longitude', 'locationLongitude']), -180, 180, 'La longitude GPS');

        if (($centerLatitude === null) !== ($centerLongitude === null)) {
            throw new LocationDraftHydrationException('Le centre de commune doit contenir une latitude et une longitude valides.');
        }

        if (($latitude === null) !== ($longitude === null)) {
            throw new LocationDraftHydrationException('Le point GPS doit contenir une latitude et une longitude valides.');
        }

        return [
            'communeName' => $this->stringValue($this->value($data, ['communeName', 'locationCommune', 'cityName'])),
            'communeInseeCode' => $this->stringValue($this->value($data, ['communeInseeCode', 'locationInseeCode', 'code']), 20),
            'postalCode' => $this->stringValue($this->value($data, ['postalCode', 'locationPostalCode']), 20),
            'departmentName' => $this->stringValue($this->value($data, ['departmentName', 'locationDepartment'])),
            'departmentCode' => $this->stringValue($this->value($data, ['departmentCode', 'locationDepartmentCode']), 20),
            'regionName' => $this->stringValue($this->value($data, ['regionName', 'locationRegion'])),
            'country' => $this->stringValue($this->value($data, ['country', 'countryName', 'locationCountry'])) ?? 'France',
            'communeCenterLatitude' => $centerLatitude,
            'communeCenterLongitude' => $centerLongitude,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'gpsAccuracy' => $this->positiveFloat($this->value($data, ['gpsAccuracy', 'accuracy', 'locationAccuracy']), 'La précision GPS'),
        ];
    }

    /** @param array<string, mixed> $data */
    private function hydrateDetectedLocation(HikeDraft|CityVisitDraft $draft, array $data): void
    {
        $draft
            ->setDetectedCommuneName($data['communeName'])
            ->setDetectedCommuneCode($data['communeInseeCode'])
            ->setDetectedDepartmentName($data['departmentName'])
            ->setDetectedRegionName($data['regionName']);
    }

    /** @param array<string, mixed> $data */
    private function resolveGeographicDestination(array $data): ?Destination
    {
        return $this->geographicHierarchyResolver->resolveCommune(
            $data['communeName'],
            $data['communeInseeCode'],
            $data['departmentName'],
            $data['regionName'],
            $data['country'],
            $data['communeCenterLatitude'],
            $data['communeCenterLongitude'],
            $data['departmentCode'],
        );
    }

    /** @param array<string, mixed> $data */
    private function syncHikePoint(HikeDraft $draft, array $data): void
    {
        if ($data['latitude'] === null || $data['longitude'] === null) {
            return;
        }

        $point = $this->primaryHikePoint($draft);
        if (!$point instanceof HikePoint) {
            $point = (new HikePoint())
                ->setType(HikePointType::Start)
                ->setTitle('Point de départ')
                ->setPosition($this->nextHikePointPosition($draft));
            $draft->addPoint($point);
        }

        $point
            ->setLatitude($data['latitude'])
            ->setLongitude($data['longitude'])
            ->setAccuracy($data['gpsAccuracy'])
            ->setDetectedCommuneName($data['communeName'])
            ->setDetectedCommuneCode($data['communeInseeCode'])
            ->setDetectedDepartmentName($data['departmentName'])
            ->setDetectedRegionName($data['regionName']);
    }

    /** @param array<string, mixed> $data */
    private function syncCityVisitPoint(CityVisitDraft $draft, array $data): void
    {
        if ($data['latitude'] === null || $data['longitude'] === null) {
            return;
        }

        $point = $this->primaryCityVisitPoint($draft);
        if (!$point instanceof CityVisitPoint) {
            $point = (new CityVisitPoint())
                ->setType(CityVisitPointType::Start)
                ->setTitle('Point principal')
                ->setPosition($this->nextCityVisitPointPosition($draft));
            $draft->addPoint($point);
        }

        $point
            ->setLatitude($data['latitude'])
            ->setLongitude($data['longitude'])
            ->setAccuracy($data['gpsAccuracy'])
            ->setDetectedCommuneName($data['communeName'])
            ->setDetectedCommuneCode($data['communeInseeCode'])
            ->setDetectedDepartmentName($data['departmentName'])
            ->setDetectedRegionName($data['regionName']);
    }

    private function primaryHikePoint(HikeDraft $draft): ?HikePoint
    {
        $points = $draft->getPoints()->toArray();
        usort($points, static fn (HikePoint $a, HikePoint $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        foreach ($points as $point) {
            if ($point->getType() === HikePointType::Start) {
                return $point;
            }
        }

        return $points[0] ?? null;
    }

    private function primaryCityVisitPoint(CityVisitDraft $draft): ?CityVisitPoint
    {
        $points = $draft->getPoints()->toArray();
        usort($points, static fn (CityVisitPoint $a, CityVisitPoint $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        foreach ($points as $point) {
            if ($point->getType() === CityVisitPointType::Start) {
                return $point;
            }
        }

        return $points[0] ?? null;
    }

    private function nextHikePointPosition(HikeDraft $draft): int
    {
        $position = 0;
        foreach ($draft->getPoints() as $point) {
            $position = max($position, $point->getPosition());
        }

        return $position + 1;
    }

    private function nextCityVisitPointPosition(CityVisitDraft $draft): int
    {
        $position = 0;
        foreach ($draft->getPoints() as $point) {
            $position = max($position, $point->getPosition());
        }

        return $position + 1;
    }

    /** @param list<string> $keys */
    private function firstRequestValue(Request $request, array $keys, int $maxLength = 150): ?string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($request->request->get($key), $maxLength);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<string> $keys */
    private function value(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function stringValue(mixed $value, int $maxLength = 150): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private function coordinate(mixed $value, float $min, float $max, string $label): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalizedValue = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalizedValue)) {
            throw new LocationDraftHydrationException(sprintf('%s doit être un nombre valide.', $label));
        }

        $coordinate = (float) $normalizedValue;
        if ($coordinate < $min || $coordinate > $max) {
            throw new LocationDraftHydrationException(sprintf('%s doit être comprise entre %s et %s.', $label, $min, $max));
        }

        return $coordinate;
    }

    private function positiveFloat(mixed $value, string $label): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalizedValue = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalizedValue)) {
            throw new LocationDraftHydrationException(sprintf('%s doit être un nombre valide.', $label));
        }

        $float = (float) $normalizedValue;
        if ($float < 0) {
            throw new LocationDraftHydrationException(sprintf('%s doit être positive.', $label));
        }

        return $float;
    }
}
