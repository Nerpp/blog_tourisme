<?php

namespace App\Service;

use App\Entity\Destination;
use App\Enum\DestinationType;
use App\Repository\DestinationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final class GeographicHierarchyResolver
{
    public function __construct(
        private readonly DestinationRepository $destinationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function resolveCommune(
        ?string $communeName,
        ?string $communeCode,
        ?string $departmentName,
        ?string $regionName,
        string $countryName = 'France',
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $departmentCode = null,
        ?string $regionCode = null,
        ?string $countryCode = null,
    ): ?Destination {
        $communeName = $this->clean($communeName);
        if ($communeName === null) {
            return null;
        }

        $cleanCommuneCode = $this->clean($communeCode);
        $cleanCountryName = $this->clean($countryName) ?? 'France';
        $cleanCountryCode = $this->countryCode($cleanCountryName, $this->clean($countryCode));
        $cleanDepartmentCode = $this->clean($departmentCode) ?? $this->departmentCodeFromCommuneCode($cleanCommuneCode, $cleanCountryCode);
        $cleanRegionName = $this->clean($regionName);
        $cleanRegionCode = $this->clean($regionCode) ?? $this->regionCode($cleanRegionName, $cleanCountryCode);

        $country = $this->findOrCreateNode($cleanCountryName, DestinationType::Country, null, $cleanCountryCode, null, null);
        $region = $cleanRegionName !== null
            ? $this->findOrCreateNode($cleanRegionName, DestinationType::Region, $country, $cleanRegionCode, null, null)
            : $country;
        $cleanDepartmentName = $this->clean($departmentName);
        $department = $cleanDepartmentName !== null
            ? $this->findOrCreateNode($cleanDepartmentName, DestinationType::Department, $region, $cleanDepartmentCode, null, null)
            : $region;

        return $this->findOrCreateNode(
            $communeName,
            DestinationType::City,
            $department,
            $cleanCommuneCode,
            $latitude,
            $longitude,
        );
    }

    private function findOrCreateNode(
        string $name,
        DestinationType $type,
        ?Destination $parent,
        ?string $code,
        ?float $latitude,
        ?float $longitude,
    ): Destination {
        $destination = $this->findReusableNode($name, $type, $code);

        if (!$destination instanceof Destination) {
            $destination = (new Destination())
                ->setName($name)
                ->setSlug($this->createUniqueSlug($name, $type, $code, $parent))
                ->setType($type)
                ->setCode($code);
            $this->entityManager->persist($destination);
        } else {
            $destination->setName($name);
            if ($code !== null) {
                $destination->setCode($code);
            }
        }

        if (!$this->sameDestination($destination->getParent(), $parent) && !$this->sameDestination($destination, $parent)) {
            $destination->setParent($parent);
        }

        if ($latitude !== null) {
            $destination->setLatitude($latitude);
        }

        if ($longitude !== null) {
            $destination->setLongitude($longitude);
        }

        return $destination;
    }

    private function findReusableNode(string $name, DestinationType $type, ?string $code): ?Destination
    {
        if ($code !== null) {
            $destination = $this->scheduledDestinationByCode($type, $code);
            if ($destination instanceof Destination) {
                return $destination;
            }

            $destination = $this->destinationRepository->findOneBy(['code' => $code, 'type' => $type]);
            if ($destination instanceof Destination) {
                return $destination;
            }

            if ($type === DestinationType::City) {
                return null;
            }
        }

        $destination = $this->destinationRepository->findOneBy(['name' => $name, 'type' => $type]);
        if ($destination instanceof Destination && ($code === null || $destination->getCode() === null)) {
            return $destination;
        }

        return null;
    }

    private function scheduledDestinationByCode(DestinationType $type, string $code): ?Destination
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Destination && $entity->getType() === $type && $entity->getCode() === $code) {
                return $entity;
            }
        }

        return null;
    }

    private function createUniqueSlug(string $name, DestinationType $type, ?string $code, ?Destination $parent): string
    {
        $baseParts = [$name];
        if ($code !== null) {
            $baseParts[] = $code;
        } elseif ($type !== DestinationType::Country) {
            $baseParts[] = $type->value;
        }

        $baseSlug = $this->slug(implode(' ', $baseParts));
        $slug = $baseSlug;
        $fallbacks = [
            sprintf('%s-%s', $baseSlug, $type->value),
        ];

        if ($parent instanceof Destination) {
            $parentSlug = $parent->getSlug();
            $parentToken = $parentSlug !== null && $parentSlug !== ''
                ? $parentSlug
                : sprintf('parent-%s', (string) ($parent->getId() ?? $this->slug($parent->getName() ?? 'destination')));
            $fallbacks[] = sprintf('%s-%s', $baseSlug, $parentToken);
        }

        foreach (array_values(array_unique($fallbacks)) as $candidate) {
            if (!$this->slugExists($slug)) {
                return $slug;
            }

            $slug = $this->truncateSlug($candidate);
        }

        $suffix = 2;
        while ($this->slugExists($slug)) {
            $suffixToken = sprintf('-%d', $suffix);
            $slug = $this->truncateSlug($baseSlug, strlen($suffixToken)).$suffixToken;
            ++$suffix;
        }

        return $slug;
    }

    private function slug(string $value): string
    {
        return trim(strtolower((string) $this->slugger->slug($value)), '-') ?: 'destination';
    }

    private function slugExists(string $slug): bool
    {
        if ($this->destinationRepository->findOneBy(['slug' => $slug]) instanceof Destination) {
            return true;
        }

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Destination && $entity->getSlug() === $slug) {
                return true;
            }
        }

        return false;
    }

    private function truncateSlug(string $slug, int $reservedLength = 0): string
    {
        $maxLength = 180 - $reservedLength;

        return rtrim(substr($slug, 0, $maxLength), '-') ?: 'destination';
    }

    private function countryCode(string $countryName, ?string $countryCode): ?string
    {
        if ($countryCode !== null) {
            return strtoupper($countryCode);
        }

        return $this->slug($countryName) === 'france' ? 'FR' : null;
    }

    private function departmentCodeFromCommuneCode(?string $communeCode, ?string $countryCode): ?string
    {
        if ($communeCode === null || $countryCode !== 'FR') {
            return null;
        }

        $communeCode = strtoupper($communeCode);
        if (preg_match('/^2[AB]/', $communeCode) === 1) {
            return substr($communeCode, 0, 2);
        }

        if (preg_match('/^9[78]/', $communeCode) === 1) {
            return substr($communeCode, 0, 3);
        }

        return substr($communeCode, 0, 2);
    }

    private function regionCode(?string $regionName, ?string $countryCode): ?string
    {
        if ($regionName === null || $countryCode !== 'FR') {
            return null;
        }

        return [
            'guadeloupe' => '01',
            'martinique' => '02',
            'guyane' => '03',
            'la-reunion' => '04',
            'mayotte' => '06',
            'ile-de-france' => '11',
            'centre-val-de-loire' => '24',
            'bourgogne-franche-comte' => '27',
            'normandie' => '28',
            'hauts-de-france' => '32',
            'grand-est' => '44',
            'pays-de-la-loire' => '52',
            'bretagne' => '53',
            'nouvelle-aquitaine' => '75',
            'occitanie' => '76',
            'auvergne-rhone-alpes' => '84',
            'provence-alpes-cote-d-azur' => '93',
            'corse' => '94',
        ][$this->slug($regionName)] ?? null;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function sameDestination(?Destination $first, ?Destination $second): bool
    {
        if (!$first instanceof Destination || !$second instanceof Destination) {
            return $first === $second;
        }

        return $first === $second || ($first->getId() !== null && $first->getId() === $second->getId());
    }
}
