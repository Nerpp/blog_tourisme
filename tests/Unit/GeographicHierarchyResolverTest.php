<?php

namespace App\Tests\Unit;

use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Repository\DestinationRepository;
use App\Service\GeographicHierarchyResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class GeographicHierarchyResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     firstName: string,
     *     firstCode: string,
     *     firstSlug: string,
     *     secondName: string,
     *     secondCode: string,
     *     secondSlug: string,
     *     departmentName: string,
     *     departmentCode: string,
     *     regionName: string,
     *     regionCode: string
     * }>
     */
    public static function homonymCommuneProvider(): iterable
    {
        yield 'Bors in Charente' => [
            'firstName' => 'Bors',
            'firstCode' => '16052',
            'firstSlug' => 'bors-16052',
            'secondName' => 'Bors',
            'secondCode' => '16053',
            'secondSlug' => 'bors-16053',
            'departmentName' => 'Charente',
            'departmentCode' => '16',
            'regionName' => 'Nouvelle-Aquitaine',
            'regionCode' => '75',
        ];

        yield 'Castillon in Pyrénées-Atlantiques' => [
            'firstName' => 'Castillon',
            'firstCode' => '64181',
            'firstSlug' => 'castillon-64181',
            'secondName' => 'Castillon',
            'secondCode' => '64182',
            'secondSlug' => 'castillon-64182',
            'departmentName' => 'Pyrénées-Atlantiques',
            'departmentCode' => '64',
            'regionName' => 'Nouvelle-Aquitaine',
            'regionCode' => '75',
        ];

        yield 'Château-Chinon in Nièvre' => [
            'firstName' => 'Château-Chinon (Ville)',
            'firstCode' => '58062',
            'firstSlug' => 'chateau-chinon-ville-58062',
            'secondName' => 'Château-Chinon (Campagne)',
            'secondCode' => '58063',
            'secondSlug' => 'chateau-chinon-campagne-58063',
            'departmentName' => 'Nièvre',
            'departmentCode' => '58',
            'regionName' => 'Bourgogne-Franche-Comté',
            'regionCode' => '27',
        ];
    }

    #[DataProvider('homonymCommuneProvider')]
    public function testHomonymCommunesCreateDistinctDestinationsAndSlugs(
        string $firstName,
        string $firstCode,
        string $firstSlug,
        string $secondName,
        string $secondCode,
        string $secondSlug,
        string $departmentName,
        string $departmentCode,
        string $regionName,
        string $regionCode,
    ): void {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $first = $resolver->resolveCommune($firstName, $firstCode, $departmentName, $regionName);
        $second = $resolver->resolveCommune($secondName, $secondCode, $departmentName, $regionName);

        self::assertInstanceOf(Destination::class, $first);
        self::assertInstanceOf(Destination::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame(DestinationType::City, $first->getType());
        self::assertSame(DestinationType::City, $second->getType());
        self::assertSame($firstCode, $first->getCode());
        self::assertSame($secondCode, $second->getCode());
        self::assertSame($firstSlug, $first->getSlug());
        self::assertSame($secondSlug, $second->getSlug());
        $department = $first->getParent();
        self::assertInstanceOf(Destination::class, $department);
        self::assertSame($department, $second->getParent());
        self::assertSame($departmentCode, $department->getCode());
        $region = $department->getParent();
        self::assertInstanceOf(Destination::class, $region);
        self::assertSame($regionCode, $region->getCode());
        $country = $region->getParent();
        self::assertInstanceOf(Destination::class, $country);
        self::assertSame('FR', $country->getCode());
        self::assertNoDuplicateSlugs($scheduled);
    }

    public function testCommuneIsReusedByInseeCode(): void
    {
        $existing = (new Destination())
            ->setName('Bors')
            ->setSlug('bors-16052')
            ->setType(DestinationType::City)
            ->setCode('16052');
        $database = [$existing];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $resolved = $resolver->resolveCommune('Bors', '16052', 'Charente', 'Nouvelle-Aquitaine');

        self::assertSame($existing, $resolved);
        self::assertSame('16052', $existing->getCode());
        self::assertSame('bors-16052', $existing->getSlug());
    }

    public function testBlankCommuneNameReturnsNullWithoutSchedulingDestination(): void
    {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        self::assertNull($resolver->resolveCommune('  ', '16052', 'Charente', 'Nouvelle-Aquitaine'));
        self::assertSame([], $scheduled);
    }

    public function testCommuneWithoutDepartmentOrRegionIsAttachedToCountry(): void
    {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $commune = $resolver->resolveCommune('Village Test', null, null, null);

        self::assertInstanceOf(Destination::class, $commune);
        self::assertSame(DestinationType::City, $commune->getType());
        self::assertSame('village-test-city', $commune->getSlug());
        $country = $commune->getParent();
        self::assertInstanceOf(Destination::class, $country);
        self::assertSame(DestinationType::Country, $country->getType());
        self::assertSame('France', $country->getName());
        self::assertSame('FR', $country->getCode());
    }

    public function testCommuneWithDifferentInseeCodeIsNotReusedByName(): void
    {
        $existing = (new Destination())
            ->setName('Bors')
            ->setSlug('bors-16052')
            ->setType(DestinationType::City)
            ->setCode('16052');
        $database = [$existing];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $resolved = $resolver->resolveCommune('Bors', '16053', 'Charente', 'Nouvelle-Aquitaine');

        self::assertInstanceOf(Destination::class, $resolved);
        self::assertNotSame($existing, $resolved);
        self::assertSame('16052', $existing->getCode());
        self::assertSame('bors-16052', $existing->getSlug());
        self::assertSame('16053', $resolved->getCode());
        self::assertSame('bors-16053', $resolved->getSlug());
        self::assertNoDuplicateSlugs([$existing, ...$scheduled]);
    }

    public function testParisDepartmentAndCommuneUseDistinctAdministrativeSlugs(): void
    {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $commune = $resolver->resolveCommune(
            'Paris',
            '75056',
            'Paris',
            'Île-de-France',
            latitude: 48.8566,
            longitude: 2.3522,
        );

        self::assertInstanceOf(Destination::class, $commune);
        self::assertSame(DestinationType::City, $commune->getType());
        self::assertSame('75056', $commune->getCode());
        self::assertSame('paris-75056', $commune->getSlug());
        self::assertSame(48.8566, $commune->getLatitude());
        self::assertSame(2.3522, $commune->getLongitude());

        $department = $commune->getParent();
        self::assertInstanceOf(Destination::class, $department);
        self::assertSame(DestinationType::Department, $department->getType());
        self::assertSame('75', $department->getCode());
        self::assertSame('paris-75', $department->getSlug());

        $region = $department->getParent();
        self::assertInstanceOf(Destination::class, $region);
        self::assertSame(DestinationType::Region, $region->getType());
        self::assertSame('11', $region->getCode());
        self::assertSame('ile-de-france-11', $region->getSlug());
    }

    public function testCorsicaAndOverseasCodesAreDerivedFromFrenchCommuneCodes(): void
    {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $ajaccio = $resolver->resolveCommune('Ajaccio', '2A004', 'Corse-du-Sud', 'Corse');
        $mamoudzou = $resolver->resolveCommune('Mamoudzou', '97611', 'Mayotte', 'Mayotte');

        self::assertInstanceOf(Destination::class, $ajaccio);
        $corsicanDepartment = $ajaccio->getParent();
        self::assertInstanceOf(Destination::class, $corsicanDepartment);
        self::assertSame('2A', $corsicanDepartment->getCode());
        $corsicanRegion = $corsicanDepartment->getParent();
        self::assertInstanceOf(Destination::class, $corsicanRegion);
        self::assertSame('94', $corsicanRegion->getCode());

        self::assertInstanceOf(Destination::class, $mamoudzou);
        $overseasDepartment = $mamoudzou->getParent();
        self::assertInstanceOf(Destination::class, $overseasDepartment);
        self::assertSame('976', $overseasDepartment->getCode());
        $overseasRegion = $overseasDepartment->getParent();
        self::assertInstanceOf(Destination::class, $overseasRegion);
        self::assertSame('06', $overseasRegion->getCode());
    }

    public function testNonFrenchCountryDoesNotDeriveFrenchAdministrativeCodes(): void
    {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $city = $resolver->resolveCommune(
            'Genève',
            '01234',
            'Canton de Genève',
            'Romandie',
            'Suisse',
            departmentCode: null,
            regionCode: null,
            countryCode: 'CH',
        );

        self::assertInstanceOf(Destination::class, $city);
        self::assertSame('01234', $city->getCode());
        $department = $city->getParent();
        self::assertInstanceOf(Destination::class, $department);
        self::assertNull($department->getCode());
        $region = $department->getParent();
        self::assertInstanceOf(Destination::class, $region);
        self::assertNull($region->getCode());
        $country = $region->getParent();
        self::assertInstanceOf(Destination::class, $country);
        self::assertSame('CH', $country->getCode());
    }

    public function testExistingAmbiguousParisDepartmentKeepsSlugAndGetsCode(): void
    {
        $france = (new Destination())
            ->setName('France')
            ->setSlug('france')
            ->setType(DestinationType::Country)
            ->setCode('FR');
        $region = (new Destination())
            ->setName('Île-de-France')
            ->setSlug('ile-de-france')
            ->setType(DestinationType::Region)
            ->setCode('11')
            ->setParent($france);
        $department = (new Destination())
            ->setName('Paris')
            ->setSlug('paris')
            ->setType(DestinationType::Department)
            ->setParent($region);

        $database = [$france, $region, $department];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);

        $commune = $resolver->resolveCommune('Paris', '75056', 'Paris', 'Île-de-France');

        self::assertInstanceOf(Destination::class, $commune);
        self::assertSame('paris-75056', $commune->getSlug());
        self::assertSame('75056', $commune->getCode());
        self::assertSame($department, $commune->getParent());
        self::assertSame('paris', $department->getSlug());
        self::assertSame('75', $department->getCode());
    }

    #[DataProvider('homonymCommuneProvider')]
    public function testRepublicationChangesGeographicDestinationForHomonymCommune(
        string $firstName,
        string $firstCode,
        string $firstSlug,
        string $secondName,
        string $secondCode,
        string $secondSlug,
        string $departmentName,
        string $departmentCode,
        string $regionName,
        string $regionCode,
    ): void {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);
        $first = $resolver->resolveCommune($firstName, $firstCode, $departmentName, $regionName);
        $hike = $this->publishedHike('homonym-test', $first);

        $second = $resolver->resolveCommune($secondName, $secondCode, $departmentName, $regionName);
        $hike->setGeographicDestination($second);

        self::assertInstanceOf(Destination::class, $first);
        self::assertInstanceOf(Destination::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame($second, $hike->getGeographicDestination());
        self::assertSame(0, $this->countHikesForDestination([$hike], $first));
        self::assertSame(1, $this->countHikesForDestination([$hike], $second));
        self::assertSame($firstSlug, $first->getSlug());
        self::assertSame($secondSlug, $second->getSlug());
        $department = $second->getParent();
        self::assertInstanceOf(Destination::class, $department);
        self::assertSame($departmentCode, $department->getCode());
        $region = $department->getParent();
        self::assertInstanceOf(Destination::class, $region);
        self::assertSame($regionCode, $region->getCode());
    }

    public function testDeletingOneHomonymContentDoesNotAffectTheOtherCommuneReference(): void
    {
        $database = [];
        $scheduled = [];
        $resolver = $this->createResolver($database, $scheduled);
        $bors16052 = $resolver->resolveCommune('Bors', '16052', 'Charente', 'Nouvelle-Aquitaine');
        $bors16053 = $resolver->resolveCommune('Bors', '16053', 'Charente', 'Nouvelle-Aquitaine');
        self::assertInstanceOf(Destination::class, $bors16052);
        self::assertInstanceOf(Destination::class, $bors16053);
        $hikes = [
            $this->publishedHike('bors-16052-hike', $bors16052),
            $this->publishedHike('bors-16053-hike', $bors16053),
        ];

        array_shift($hikes);

        self::assertSame(0, $this->countHikesForDestination($hikes, $bors16052));
        self::assertSame(1, $this->countHikesForDestination($hikes, $bors16053));
        self::assertSame('bors-16052', $bors16052->getSlug());
        self::assertSame('bors-16053', $bors16053->getSlug());
        self::assertNoDuplicateSlugs($scheduled);
    }

    /**
     * @param list<Destination> $database
     * @param list<Destination> $scheduled
     */
    private function createResolver(array &$database, array &$scheduled): GeographicHierarchyResolver
    {
        $repository = $this->createStub(DestinationRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            function (array $criteria) use (&$database, &$scheduled): ?Destination {
                return $this->findOneBy([...$database, ...$scheduled], $criteria);
            },
        );

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturnCallback(
            static function () use (&$scheduled): array {
                return $scheduled;
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$scheduled): void {
                if ($entity instanceof Destination) {
                    $scheduled[] = $entity;
                }
            },
        );

        return new GeographicHierarchyResolver($repository, $entityManager, new AsciiSlugger('fr'));
    }

    /**
     * @param list<Destination> $destinations
     * @param array<string, mixed> $criteria
     */
    private function findOneBy(array $destinations, array $criteria): ?Destination
    {
        foreach ($destinations as $destination) {
            foreach ($criteria as $field => $expected) {
                $actual = match ($field) {
                    'code' => $destination->getCode(),
                    'name' => $destination->getName(),
                    'slug' => $destination->getSlug(),
                    'type' => $destination->getType(),
                    default => null,
                };

                if ($actual !== $expected) {
                    continue 2;
                }
            }

            return $destination;
        }

        return null;
    }

    private function publishedHike(string $slug, ?Destination $geographicDestination): HikeDraft
    {
        return (new HikeDraft())
            ->setTitle($slug)
            ->setSlug($slug)
            ->setStatus(HikeDraftStatus::Finished)
            ->setFinishedAt(new DateTimeImmutable('2026-06-02 12:00:00'))
            ->setGeographicDestination($geographicDestination);
    }

    /**
     * @param list<HikeDraft> $hikes
     */
    private function countHikesForDestination(array $hikes, Destination $destination): int
    {
        return count(array_filter(
            $hikes,
            static fn (HikeDraft $hike): bool => $hike->getGeographicDestination() === $destination,
        ));
    }

    /**
     * @param list<Destination> $destinations
     */
    private static function assertNoDuplicateSlugs(array $destinations): void
    {
        $uniqueDestinations = [];
        foreach ($destinations as $destination) {
            $uniqueDestinations[spl_object_id($destination)] = $destination;
        }

        $slugs = array_map(
            static fn (Destination $destination): ?string => $destination->getSlug(),
            array_values($uniqueDestinations),
        );
        $slugs = array_values(array_filter($slugs, static fn (?string $slug): bool => $slug !== null));

        self::assertSame($slugs, array_values(array_unique($slugs)));
    }
}
