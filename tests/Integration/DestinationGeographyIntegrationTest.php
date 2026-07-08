<?php

namespace App\Tests\Integration;

use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Repository\DestinationRepository;
use App\Service\GeographicHierarchyResolver;

final class DestinationGeographyIntegrationTest extends IntegrationTestCase
{
    public function testHomonymCommunesAreSeparatedByInseeCodeWithDoctrineRepository(): void
    {
        $token = bin2hex(random_bytes(4));
        $communeName = 'Bors Integration '.$token;

        $first = $this->resolver()->resolveCommune($communeName, '99101', 'Charente Integration '.$token, 'Nouvelle-Aquitaine');
        $second = $this->resolver()->resolveCommune($communeName, '99102', 'Charente Integration '.$token, 'Nouvelle-Aquitaine');
        $this->entityManager->flush();

        self::assertInstanceOf(Destination::class, $first);
        self::assertInstanceOf(Destination::class, $second);
        self::assertNotSame($first->getId(), $second->getId());
        self::assertSame(DestinationType::City, $first->getType());
        self::assertSame(DestinationType::City, $second->getType());
        self::assertSame('99101', $first->getCode());
        self::assertSame('99102', $second->getCode());
        self::assertNotSame($first->getSlug(), $second->getSlug());
        self::assertSame($first->getParent()?->getId(), $second->getParent()?->getId());
    }

    public function testCommuneIsReusedByInseeCodeThroughRealRepository(): void
    {
        $token = bin2hex(random_bytes(4));
        $code = '992'.substr($token, 0, 2);

        $first = $this->resolver()->resolveCommune('Code Reuse '.$token, $code, 'Reuse Department '.$token, 'Occitanie');
        $this->entityManager->flush();

        $second = $this->resolver()->resolveCommune('Code Reuse Renamed '.$token, $code, 'Reuse Department '.$token, 'Occitanie');
        $this->entityManager->flush();

        self::assertInstanceOf(Destination::class, $first);
        self::assertInstanceOf(Destination::class, $second);
        self::assertSame($first->getId(), $second->getId());
        self::assertSame($code, $second->getCode());
        self::assertSame('Code Reuse Renamed '.$token, $second->getName());
    }

    public function testParisCommuneDoesNotReuseParisDepartmentNode(): void
    {
        $token = bin2hex(random_bytes(4));

        $commune = $this->resolver()->resolveCommune(
            'Paris',
            '98'.substr($token, 0, 3),
            'Paris',
            'Ile-de-France',
            latitude: 48.8566,
            longitude: 2.3522,
        );
        $this->entityManager->flush();

        self::assertInstanceOf(Destination::class, $commune);
        self::assertSame(DestinationType::City, $commune->getType());
        self::assertSame(48.8566, $commune->getLatitude());
        self::assertSame(2.3522, $commune->getLongitude());

        $department = $commune->getParent();
        self::assertInstanceOf(Destination::class, $department);
        self::assertSame(DestinationType::Department, $department->getType());
        self::assertSame('Paris', $department->getName());
        self::assertNotSame($department->getId(), $commune->getId());
    }

    public function testEditorialDestinationAndGeographicDestinationRemainSeparate(): void
    {
        $token = bin2hex(random_bytes(4));
        $editorial = (new Destination())
            ->setName('Massif Editorial '.$token)
            ->setSlug('massif-editorial-'.$token)
            ->setType(DestinationType::Area);

        $geographic = $this->resolver()->resolveCommune('Geo Commune '.$token, '993'.substr($token, 0, 2), 'Geo Department '.$token, 'Occitanie');
        $hike = (new HikeDraft())
            ->setTitle('Integration hike '.$token)
            ->setSlug('integration-hike-'.$token)
            ->setStatus(HikeDraftStatus::Finished)
            ->setDestination($editorial)
            ->setGeographicDestination($geographic);

        $this->entityManager->persist($editorial);
        $this->entityManager->persist($hike);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->find(HikeDraft::class, $hike->getId());
        self::assertInstanceOf(HikeDraft::class, $stored);
        self::assertSame($editorial->getId(), $stored->getDestination()?->getId());
        self::assertSame($geographic?->getId(), $stored->getGeographicDestination()?->getId());
        self::assertNotSame($stored->getDestination()?->getId(), $stored->getGeographicDestination()?->getId());
    }

    private function resolver(): GeographicHierarchyResolver
    {
        $resolver = $this->service(GeographicHierarchyResolver::class);
        self::assertInstanceOf(GeographicHierarchyResolver::class, $resolver);

        $repository = $this->service(DestinationRepository::class);
        self::assertInstanceOf(DestinationRepository::class, $repository);

        return $resolver;
    }
}
