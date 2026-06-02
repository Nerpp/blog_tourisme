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
    ): ?Destination {
        $communeName = $this->clean($communeName);
        if ($communeName === null) {
            return null;
        }

        $country = $this->findOrCreateNode($countryName, DestinationType::Country, null, null, null, null);
        $region = $this->clean($regionName) !== null
            ? $this->findOrCreateNode((string) $this->clean($regionName), DestinationType::Region, $country, null, null, null)
            : $country;
        $department = $this->clean($departmentName) !== null
            ? $this->findOrCreateNode((string) $this->clean($departmentName), DestinationType::Department, $region, null, null, null)
            : $region;

        return $this->findOrCreateNode(
            $communeName,
            DestinationType::City,
            $department,
            $this->clean($communeCode),
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
        $destination = $code !== null
            ? $this->destinationRepository->findOneBy(['code' => $code, 'type' => $type])
            : null;

        if (!$destination instanceof Destination) {
            $destination = $this->destinationRepository->findOneBy(['name' => $name, 'type' => $type]);
        }

        if (!$destination instanceof Destination) {
            $destination = (new Destination())
                ->setName($name)
                ->setSlug($this->createUniqueSlug($name))
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

    private function createUniqueSlug(string $name): string
    {
        $baseSlug = trim(strtolower((string) $this->slugger->slug($name)), '-') ?: 'destination';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->destinationRepository->findOneBy(['slug' => $slug]) instanceof Destination) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
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
