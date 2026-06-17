<?php

namespace App\Tests\Unit\Geography;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;
use App\Enum\HikePointType;
use App\Repository\DestinationRepository;
use App\Service\GeographicHierarchyResolver;
use App\Service\Geography\LocationDraftHydrationException;
use App\Service\Geography\LocationDraftHydrator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class LocationDraftHydratorTest extends TestCase
{
    public function testDataFromRequestNormalizesAliasesAndCommaCoordinates(): void
    {
        $database = [];
        $scheduled = [];
        $hydrator = $this->createHydrator($database, $scheduled);
        $request = Request::create('/', 'POST', [
            'cityName' => ' Nyer ',
            'code' => ' 66124 ',
            'postalCode' => ' 66360 ',
            'department' => ' Pyrénées-Orientales ',
            'departmentCode' => ' 66 ',
            'region' => ' Occitanie ',
            'country' => ' ',
            'locationCommuneCenterLatitude' => '42,541',
            'locationCommuneCenterLongitude' => '2,277',
            'latitude' => '42,542',
            'longitude' => '2,278',
            'accuracy' => '12,5',
        ]);

        $data = $hydrator->dataFromRequest($request, requireCommune: true);

        self::assertSame('Nyer', $data['communeName']);
        self::assertSame('66124', $data['communeInseeCode']);
        self::assertSame('66360', $data['postalCode']);
        self::assertSame('Pyrénées-Orientales', $data['departmentName']);
        self::assertSame('66', $data['departmentCode']);
        self::assertSame('Occitanie', $data['regionName']);
        self::assertSame('France', $data['country']);
        self::assertSame(42.541, $data['communeCenterLatitude']);
        self::assertSame(2.277, $data['communeCenterLongitude']);
        self::assertSame(42.542, $data['latitude']);
        self::assertSame(2.278, $data['longitude']);
        self::assertSame(12.5, $data['gpsAccuracy']);
    }

    public function testDataFromRequestRequiresSelectedCommuneWhenAsked(): void
    {
        $database = [];
        $scheduled = [];
        $hydrator = $this->createHydrator($database, $scheduled);

        $this->expectException(LocationDraftHydrationException::class);
        $this->expectExceptionMessage('Sélectionnez une commune dans la liste avant de créer la visite.');

        $hydrator->dataFromRequest(Request::create('/', 'POST', ['locationCommune' => 'Nyer']), requireCommune: true);
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('invalidRequestProvider')]
    public function testDataFromRequestRejectsInvalidCoordinates(array $parameters, string $expectedMessage): void
    {
        $database = [];
        $scheduled = [];
        $hydrator = $this->createHydrator($database, $scheduled);

        $this->expectException(LocationDraftHydrationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $hydrator->dataFromRequest(Request::create('/', 'POST', $parameters));
    }

    /**
     * @return iterable<string, array{parameters: array<string, string>, expectedMessage: string}>
     */
    public static function invalidRequestProvider(): iterable
    {
        yield 'incomplete commune center' => [
            'parameters' => ['communeCenterLatitude' => '42.5'],
            'expectedMessage' => 'Le centre de commune doit contenir une latitude et une longitude valides.',
        ];

        yield 'incomplete gps point' => [
            'parameters' => ['locationLatitude' => '42.5'],
            'expectedMessage' => 'Le point GPS doit contenir une latitude et une longitude valides.',
        ];

        yield 'invalid gps number' => [
            'parameters' => ['latitude' => 'north', 'longitude' => '2.2'],
            'expectedMessage' => 'La latitude GPS doit être un nombre valide.',
        ];

        yield 'out of range gps number' => [
            'parameters' => ['latitude' => '91', 'longitude' => '2.2'],
            'expectedMessage' => 'La latitude GPS doit être comprise entre -90 et 90.',
        ];

        yield 'negative accuracy' => [
            'parameters' => ['accuracy' => '-1'],
            'expectedMessage' => 'La précision GPS doit être positive.',
        ];
    }

    public function testHydrateHikeDraftCreatesStartPointAndGeographicDestination(): void
    {
        $database = [];
        $scheduled = [];
        $hydrator = $this->createHydrator($database, $scheduled);
        $draft = new HikeDraft();

        $hydrator->hydrateHikeDraft($draft, [
            'communeName' => 'Nyer',
            'communeInseeCode' => '66124',
            'departmentName' => 'Pyrénées-Orientales',
            'departmentCode' => '66',
            'regionName' => 'Occitanie',
            'country' => 'France',
            'communeCenterLatitude' => '42.541',
            'communeCenterLongitude' => '2.277',
            'latitude' => '42.542',
            'longitude' => '2.278',
            'gpsAccuracy' => '7.5',
        ]);

        $destination = $draft->getGeographicDestination();
        self::assertInstanceOf(Destination::class, $destination);
        self::assertSame(DestinationType::City, $destination->getType());
        self::assertSame('Nyer', $destination->getName());
        self::assertSame('66124', $destination->getCode());
        self::assertSame('nyer-66124', $destination->getSlug());
        self::assertSame(42.541, $destination->getLatitude());
        self::assertSame(2.277, $destination->getLongitude());
        self::assertSame('Nyer', $draft->getDetectedCommuneName());
        self::assertSame('66124', $draft->getDetectedCommuneCode());
        self::assertSame('Pyrénées-Orientales', $draft->getDetectedDepartmentName());
        self::assertSame('Occitanie', $draft->getDetectedRegionName());

        self::assertCount(1, $draft->getPoints());
        $point = $draft->getPoints()->first();
        self::assertInstanceOf(HikePoint::class, $point);
        self::assertSame(HikePointType::Start, $point->getType());
        self::assertSame('Point de départ', $point->getTitle());
        self::assertSame(1, $point->getPosition());
        self::assertSame(42.542, $point->getLatitude());
        self::assertSame(2.278, $point->getLongitude());
        self::assertSame(7.5, $point->getAccuracy());
        self::assertSame('Nyer', $point->getDetectedCommuneName());
        self::assertSame($draft, $point->getHikeDraft());
    }

    public function testHydrateCityVisitDraftUpdatesExistingStartPointWithoutCreatingDuplicate(): void
    {
        $database = [];
        $scheduled = [];
        $hydrator = $this->createHydrator($database, $scheduled);
        $draft = new CityVisitDraft();
        $otherPoint = (new CityVisitPoint())
            ->setType(CityVisitPointType::Other)
            ->setTitle('Belvédère')
            ->setPosition(1)
            ->setLatitude(41.0)
            ->setLongitude(2.0);
        $startPoint = (new CityVisitPoint())
            ->setType(CityVisitPointType::Start)
            ->setTitle('Départ existant')
            ->setPosition(2)
            ->setLatitude(41.5)
            ->setLongitude(2.5);
        $draft->addPoint($otherPoint);
        $draft->addPoint($startPoint);

        $hydrator->hydrateCityVisitDraft($draft, [
            'communeName' => 'Perpignan',
            'communeInseeCode' => '66136',
            'departmentName' => 'Pyrénées-Orientales',
            'departmentCode' => '66',
            'regionName' => 'Occitanie',
            'latitude' => '42.698',
            'longitude' => '2.895',
            'gpsAccuracy' => '4',
        ]);

        $destination = $draft->getGeographicDestination();
        self::assertInstanceOf(Destination::class, $destination);
        self::assertSame('66136', $destination->getCode());
        self::assertCount(2, $draft->getPoints());
        self::assertSame(41.0, $otherPoint->getLatitude());
        self::assertSame(2.0, $otherPoint->getLongitude());
        self::assertSame(42.698, $startPoint->getLatitude());
        self::assertSame(2.895, $startPoint->getLongitude());
        self::assertSame(4.0, $startPoint->getAccuracy());
        self::assertSame('Perpignan', $startPoint->getDetectedCommuneName());
        self::assertSame('66136', $startPoint->getDetectedCommuneCode());
        self::assertSame($draft, $startPoint->getCityVisitDraft());
    }

    public function testHydrateCityVisitDraftWithoutGpsDoesNotCreatePoint(): void
    {
        $database = [];
        $scheduled = [];
        $hydrator = $this->createHydrator($database, $scheduled);
        $draft = new CityVisitDraft();

        $hydrator->hydrateCityVisitDraft($draft, [
            'communeName' => 'Céret',
            'communeInseeCode' => '66049',
            'departmentName' => 'Pyrénées-Orientales',
            'regionName' => 'Occitanie',
        ]);

        self::assertInstanceOf(Destination::class, $draft->getGeographicDestination());
        self::assertSame('Céret', $draft->getDetectedCommuneName());
        self::assertCount(0, $draft->getPoints());
    }

    /**
     * @param list<Destination> $database
     * @param list<Destination> $scheduled
     */
    private function createHydrator(array &$database, array &$scheduled): LocationDraftHydrator
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

        return new LocationDraftHydrator(new GeographicHierarchyResolver($repository, $entityManager, new AsciiSlugger('fr')));
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
}
