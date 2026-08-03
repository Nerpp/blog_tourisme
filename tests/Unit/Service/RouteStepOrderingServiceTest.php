<?php

namespace App\Tests\Unit\Service;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\CityVisitPointMedia;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Service\RouteStep\RouteStepOrderingException;
use App\Service\RouteStep\RouteStepOrderingService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class RouteStepOrderingServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private RouteStepOrderingService $service;

    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    /** @var list<array{object, LockMode|int, \DateTimeInterface|int|null}> */
    private array $locks = [];

    /** @var list<list<int>> */
    private array $flushPositionSnapshots = [];

    private int $flushes = 0;
    private int $transactions = 0;
    private int $rolledBackTransactions = 0;
    private ?\Throwable $flushFailure = null;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->removed = [];
        $this->locks = [];
        $this->flushPositionSnapshots = [];
        $this->flushes = 0;
        $this->transactions = 0;
        $this->rolledBackTransactions = 0;
        $this->flushFailure = null;

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(function (callable $operation): mixed {
                ++$this->transactions;

                try {
                    return $operation();
                } catch (\Throwable $exception) {
                    ++$this->rolledBackTransactions;

                    throw $exception;
                }
            });
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function (object $entity): void {
                $this->persisted[] = $entity;
            });
        $this->entityManager
            ->method('flush')
            ->willReturnCallback(function (): void {
                ++$this->flushes;
                $this->flushPositionSnapshots[] = array_values(array_map(
                    static fn (object $entity): int => $entity instanceof HikePoint || $entity instanceof CityVisitPoint
                        ? $entity->getPosition()
                        : 0,
                    $this->persisted,
                ));

                if ($this->flushFailure !== null) {
                    $exception = $this->flushFailure;
                    $this->flushFailure = null;

                    throw $exception;
                }
            });
        $this->entityManager
            ->method('lock')
            ->willReturnCallback(function (
                object $entity,
                LockMode|int $lockMode,
                \DateTimeInterface|int|null $lockVersion = null,
            ): void {
                $this->locks[] = [$entity, $lockMode, $lockVersion];
            });
        $this->entityManager
            ->method('remove')
            ->willReturnCallback(function (object $entity): void {
                $this->removed[] = $entity;
            });

        $this->service = new RouteStepOrderingService($this->entityManager);
    }

    public function testInsertAtEndAppendsTheStepAndKeepsContinuousPositions(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);
        $inserted = $this->hikePoint(null, 40);

        $this->service->insertAtPosition($draft, $inserted, 4);

        $this->assertOrder($draft, [...$points, $inserted]);
        self::assertSame($draft, $inserted->getHikeDraft());
        $this->assertCanonicalWrite(4);
    }

    public function testInsertAtBeginningShiftsEveryExistingStep(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);
        $inserted = $this->hikePoint(null, 40);

        $this->service->insertAtPosition($draft, $inserted, 1);

        $this->assertOrder($draft, [$inserted, ...$points]);
        $this->assertCanonicalWrite(4);
    }

    public function testInsertInMiddleShiftsOnlyTheFollowingSteps(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);
        $inserted = $this->hikePoint(null, 40);

        $this->service->insertAtPosition($draft, $inserted, 2);

        $this->assertOrder($draft, [$points[0], $inserted, $points[1], $points[2]]);
        $this->assertCanonicalWrite(4);
    }

    public function testInsertIntoEmptyDraftCreatesTheOnlyStepAtPositionOne(): void
    {
        $draft = new CityVisitDraft();
        $inserted = $this->cityVisitPoint(null, 18);

        $this->service->insertAtPosition($draft, $inserted, 1);

        $this->assertOrder($draft, [$inserted]);
        self::assertSame($draft, $inserted->getCityVisitDraft());
        $this->assertCanonicalWrite(1);
    }

    public function testMoveFromPositionFourToPositionTwo(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3, 4]);

        $this->service->moveToPosition($draft, $points[3], 2);

        $this->assertOrder($draft, [$points[0], $points[3], $points[1], $points[2]]);
        $this->assertCanonicalWrite(4);
    }

    public function testMoveFromPositionOneToPositionFour(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3, 4]);

        $this->service->moveToPosition($draft, $points[0], 4);

        $this->assertOrder($draft, [$points[1], $points[2], $points[3], $points[0]]);
        $this->assertCanonicalWrite(4);
    }

    public function testMovingToCurrentPositionIsAnOrderNoOp(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3, 4]);

        $this->service->moveToPosition($draft, $points[2], 3);

        $this->assertOrder($draft, $points);
        $this->assertCanonicalWrite(4);
    }

    public function testMovePreservesGpsEditorialDataAndMediaRelations(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3, 4]);
        $moved = $points[3]
            ->setLatitude(42.617845)
            ->setLongitude(2.421376)
            ->setAccuracy(0.35)
            ->setCoordinatesInherited(true)
            ->setTitle('Belvédère')
            ->setNote('Rester sur le sentier balisé.')
            ->setDetectedCommuneName('Eus')
            ->setDetectedCommuneCode('66074')
            ->setDetectedDepartmentName('Pyrénées-Orientales')
            ->setDetectedRegionName('Occitanie');
        $firstAsset = (new MediaAsset())
            ->setCaption('Panorama inchangé')
            ->setMetadata(['source' => 'unit-test']);
        $secondAsset = (new MediaAsset())->setCaption('Détail inchangé');
        $firstLink = (new HikePointMedia())->setMediaAsset($firstAsset);
        $secondLink = (new HikePointMedia())->setMediaAsset($secondAsset);
        $moved->addMediaLink($firstLink)->addMediaLink($secondLink);
        $mediaCollection = $moved->getMediaLinks();
        $mediaLinks = $mediaCollection->toArray();

        $this->service->moveToPosition($draft, $moved, 2);

        $this->assertOrder($draft, [$points[0], $moved, $points[1], $points[2]]);
        self::assertSame(42.617845, $moved->getLatitude());
        self::assertSame(2.421376, $moved->getLongitude());
        self::assertSame(0.35, $moved->getAccuracy());
        self::assertTrue($moved->hasInheritedCoordinates());
        self::assertSame('Belvédère', $moved->getTitle());
        self::assertSame('Rester sur le sentier balisé.', $moved->getNote());
        self::assertSame('Eus', $moved->getDetectedCommuneName());
        self::assertSame('66074', $moved->getDetectedCommuneCode());
        self::assertSame('Pyrénées-Orientales', $moved->getDetectedDepartmentName());
        self::assertSame('Occitanie', $moved->getDetectedRegionName());
        self::assertSame($mediaCollection, $moved->getMediaLinks());
        self::assertSame($mediaLinks, $moved->getMediaLinks()->toArray());
        self::assertSame($moved, $firstLink->getHikePoint());
        self::assertSame($moved, $secondLink->getHikePoint());
        self::assertSame($firstAsset, $firstLink->getMediaAsset());
        self::assertSame($secondAsset, $secondLink->getMediaAsset());
        self::assertSame('Panorama inchangé', $firstAsset->getCaption());
        self::assertSame(['source' => 'unit-test'], $firstAsset->getMetadata());
    }

    public function testMovePreservesAbsentCoordinates(): void
    {
        [$draft, $points] = $this->cityVisitFixture([1, 2]);
        $moved = $points[0];
        self::assertNull($moved->getLatitude());
        self::assertNull($moved->getLongitude());

        $this->service->moveToPosition($draft, $moved, 2);

        $this->assertOrder($draft, [$points[1], $moved]);
        self::assertNull($moved->getLatitude());
        self::assertNull($moved->getLongitude());
        self::assertNull($moved->getAccuracy());
    }

    public function testRemoveMiddleStepDeletesOnlyItAndCompactsRemainingPositions(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3, 4]);
        $remainingGps = [
            $points[0]->setLatitude(42.1)->setLongitude(2.1)->setAccuracy(1.1),
            $points[2]->setLatitude(42.3)->setLongitude(2.3)->setAccuracy(1.3),
            $points[3]->setLatitude(42.4)->setLongitude(2.4)->setAccuracy(1.4),
        ];

        $this->service->removeAndCompact($draft, $points[1]);

        $this->assertOrder($draft, [$points[0], $points[2], $points[3]]);
        self::assertFalse($draft->getPoints()->contains($points[1]));
        self::assertNull($points[1]->getHikeDraft());
        self::assertSame([$points[1]], $this->removed);
        self::assertSame([
            [42.1, 2.1, 1.1],
            [42.3, 2.3, 1.3],
            [42.4, 2.4, 1.4],
        ], array_map(
            static fn (HikePoint $point): array => [$point->getLatitude(), $point->getLongitude(), $point->getAccuracy()],
            $remainingGps,
        ));
        self::assertSame(1, $this->transactions);
        self::assertSame(2, $this->flushes);
        self::assertSame([], $this->persisted);
    }

    public function testNormalizePositionsSortsByCurrentOrderAndRemovesHoles(): void
    {
        [$draft, $points] = $this->hikeFixture([9, 2, 6]);

        $this->service->normalizePositions($draft);

        $this->assertOrder($draft, [$points[1], $points[2], $points[0]]);
        $this->assertCanonicalWrite(3);
    }

    public function testReorderUsesRequestedIdsForCityVisitSteps(): void
    {
        [$draft, $points] = $this->cityVisitFixture([2, 3, 1]);
        $asset = (new MediaAsset())->setCaption('Toujours lié au musée');
        $media = (new CityVisitPointMedia())->setMediaAsset($asset);
        $points[2]->addMediaLink($media);

        $ordered = $this->service->reorder($draft, [2, 3, 1]);

        self::assertSame([$points[1], $points[2], $points[0]], $ordered);
        $this->assertOrder($draft, [$points[1], $points[2], $points[0]]);
        self::assertSame([$media], $points[2]->getMediaLinks()->toArray());
        self::assertSame($points[2], $media->getCityVisitPoint());
        self::assertSame($asset, $media->getMediaAsset());
        self::assertSame('Toujours lié au musée', $asset->getCaption());
        $this->assertCanonicalWrite(3);
    }

    public function testInsertRejectsStepOwnedByAnotherDraftWithoutMutation(): void
    {
        $target = new HikeDraft();
        $otherDraft = new HikeDraft();
        $foreignStep = $this->hikePoint(1, 1);
        $otherDraft->addPoint($foreignStep);

        $this->assertRejected(
            fn () => $this->service->insertAtPosition($target, $foreignStep, 1),
            'Cette étape appartient à un autre parcours.',
        );

        self::assertSame($otherDraft, $foreignStep->getHikeDraft());
        self::assertTrue($otherDraft->getPoints()->contains($foreignStep));
        self::assertTrue($target->getPoints()->isEmpty());
        self::assertSame(0, $this->transactions);
    }

    public function testMoveRejectsStepThatIsNotOwnedByTheDraft(): void
    {
        [$target] = $this->hikeFixture([1]);
        [$otherDraft, $foreignPoints] = $this->hikeFixture([1]);

        $this->assertRejected(
            fn () => $this->service->moveToPosition($target, $foreignPoints[0], 1),
            'Cette étape n’appartient pas à ce parcours.',
        );

        self::assertSame($otherDraft, $foreignPoints[0]->getHikeDraft());
        self::assertSame(1, $this->transactions);
        self::assertSame(1, $this->rolledBackTransactions);
        self::assertSame(0, $this->flushes);
    }

    public function testReorderRejectsDuplicateIdsBeforeStartingTransaction(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);

        $this->assertRejected(
            fn () => $this->service->reorder($draft, [1, 1, 3]),
            'La liste des étapes contient un doublon.',
        );

        $this->assertCurrentPositions($points, [1, 2, 3]);
        self::assertSame(0, $this->transactions);
    }

    public function testReorderRejectsIncompleteListInsideTransaction(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);

        $this->assertRejected(
            fn () => $this->service->reorder($draft, [1, 2]),
            'La liste doit contenir exactement toutes les étapes du parcours.',
        );

        $this->assertCurrentPositions($points, [1, 2, 3]);
        self::assertSame(1, $this->transactions);
        self::assertSame(1, $this->rolledBackTransactions);
        self::assertSame(0, $this->flushes);
    }

    public function testReorderRejectsUnknownIdInsideTransaction(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);

        $this->assertRejected(
            fn () => $this->service->reorder($draft, [1, 2, 999]),
            'Une étape demandée n’appartient pas à ce parcours.',
        );

        $this->assertCurrentPositions($points, [1, 2, 3]);
        self::assertSame(1, $this->transactions);
        self::assertSame(1, $this->rolledBackTransactions);
        self::assertSame(0, $this->flushes);
    }

    public function testReorderRejectsUnpersistedStepInsideTransaction(): void
    {
        $draft = new HikeDraft();
        $persisted = $this->hikePoint(1, 1);
        $unpersisted = $this->hikePoint(null, 2);
        $draft->addPoint($persisted)->addPoint($unpersisted);

        $this->assertRejected(
            fn () => $this->service->reorder($draft, [1, 2]),
            'Une étape non enregistrée empêche la réorganisation.',
        );

        $this->assertCurrentPositions([$persisted, $unpersisted], [1, 2]);
        self::assertSame(1, $this->transactions);
        self::assertSame(1, $this->rolledBackTransactions);
        self::assertSame(0, $this->flushes);
    }

    public function testInsertRejectsIncompatibleStepType(): void
    {
        $draft = new HikeDraft();
        $step = $this->cityVisitPoint(null, 1);

        $this->assertRejected(
            fn () => $this->service->insertAtPosition($draft, $step, 1),
            'Le type de l’étape ne correspond pas au parcours.',
        );

        self::assertNull($step->getCityVisitDraft());
        self::assertSame(0, $this->transactions);
    }

    public function testInsertRejectsAnIdenticalStepAlreadyInDraft(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2]);

        $this->assertRejected(
            fn () => $this->service->insertAtPosition($draft, $points[0], 1),
            'Cette étape appartient déjà au parcours.',
        );

        $this->assertOrder($draft, $points);
        self::assertSame(1, $this->transactions);
        self::assertSame(1, $this->rolledBackTransactions);
        self::assertSame(0, $this->flushes);
    }

    public function testInsertAndMoveRejectOutOfRangePositions(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2]);

        foreach ([0, 4] as $position) {
            $this->assertRejected(
                fn () => $this->service->insertAtPosition($draft, $this->hikePoint(null, 1), $position),
                'La position d’insertion est invalide.',
            );
        }
        foreach ([0, 3] as $position) {
            $this->assertRejected(
                fn () => $this->service->moveToPosition($draft, $points[0], $position),
                'La position de destination est invalide.',
            );
        }

        $this->assertOrder($draft, $points);
        self::assertSame(4, $this->transactions);
        self::assertSame(4, $this->rolledBackTransactions);
        self::assertSame(0, $this->flushes);
    }

    public function testPersistedDraftIsPessimisticallyLockedBeforeMutation(): void
    {
        [$draft, $points] = $this->hikeFixture([1]);
        $this->setId($draft, 42);

        $this->service->moveToPosition($draft, $points[0], 1);

        self::assertSame([[$draft, LockMode::PESSIMISTIC_WRITE, null]], $this->locks);
        self::assertSame(1, $this->transactions);
    }

    public function testFlushFailureEscapesTransactionAndMarksItAsRolledBack(): void
    {
        [$draft, $points] = $this->hikeFixture([1, 2, 3]);
        $failure = new \RuntimeException('Échec simulé du flush.');
        $this->flushFailure = $failure;

        try {
            $this->service->moveToPosition($draft, $points[2], 1);
            self::fail('Le service aurait dû propager l’échec transactionnel.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $this->transactions);
        self::assertSame(1, $this->rolledBackTransactions);
        self::assertSame(1, $this->flushes);
        self::assertSame([], $this->removed);
    }

    /**
     * @param list<int> $positions
     *
     * @return array{HikeDraft, list<HikePoint>}
     */
    private function hikeFixture(array $positions): array
    {
        $draft = new HikeDraft();
        $points = [];
        foreach ($positions as $index => $position) {
            $point = $this->hikePoint($index + 1, $position);
            $draft->addPoint($point);
            $points[] = $point;
        }

        return [$draft, $points];
    }

    /**
     * @param list<int> $positions
     *
     * @return array{CityVisitDraft, list<CityVisitPoint>}
     */
    private function cityVisitFixture(array $positions): array
    {
        $draft = new CityVisitDraft();
        $points = [];
        foreach ($positions as $index => $position) {
            $point = $this->cityVisitPoint($index + 1, $position);
            $draft->addPoint($point);
            $points[] = $point;
        }

        return [$draft, $points];
    }

    private function hikePoint(?int $id, int $position): HikePoint
    {
        $point = (new HikePoint())
            ->setTitle($id === null ? 'Nouvelle étape' : sprintf('Étape %d', $id))
            ->setPosition($position);
        if ($id !== null) {
            $this->setId($point, $id);
        }

        return $point;
    }

    private function cityVisitPoint(?int $id, int $position): CityVisitPoint
    {
        $point = (new CityVisitPoint())
            ->setTitle($id === null ? 'Nouvelle étape' : sprintf('Étape %d', $id))
            ->setPosition($position);
        if ($id !== null) {
            $this->setId($point, $id);
        }

        return $point;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    /**
     * @param HikeDraft|CityVisitDraft       $draft
     * @param list<HikePoint|CityVisitPoint> $expected
     */
    private function assertOrder(HikeDraft|CityVisitDraft $draft, array $expected): void
    {
        self::assertSame($expected, $this->service->orderedSteps($draft));
        self::assertSame(
            $expected === [] ? [] : range(1, count($expected)),
            array_map(
                static fn (HikePoint|CityVisitPoint $point): int => $point->getPosition(),
                $expected,
            ),
        );
    }

    /** @param list<HikePoint|CityVisitPoint> $points */
    private function assertCurrentPositions(array $points, array $expectedPositions): void
    {
        self::assertSame($expectedPositions, array_map(
            static fn (HikePoint|CityVisitPoint $point): int => $point->getPosition(),
            $points,
        ));
    }

    private function assertCanonicalWrite(int $stepCount): void
    {
        self::assertSame(1, $this->transactions);
        self::assertSame(2, $this->flushes);
        self::assertCount($stepCount, $this->persisted);
        self::assertCount(2, $this->flushPositionSnapshots);

        $temporaryPositions = $this->flushPositionSnapshots[0];
        self::assertCount($stepCount, $temporaryPositions);
        self::assertCount($stepCount, array_unique($temporaryPositions));
        foreach ($temporaryPositions as $position) {
            self::assertLessThan(0, $position);
        }

        self::assertSame(
            $stepCount === 0 ? [] : range(1, $stepCount),
            $this->flushPositionSnapshots[1],
        );
    }

    /** @param callable(): mixed $operation */
    private function assertRejected(callable $operation, string $expectedMessage): void
    {
        try {
            $operation();
            self::fail('Une RouteStepOrderingException était attendue.');
        } catch (RouteStepOrderingException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }
}
