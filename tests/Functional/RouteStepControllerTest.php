<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\CityVisitPointMedia;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\CityVisitPointType;
use App\Enum\HikePointType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class RouteStepControllerTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string}> */
    public static function routeKindProvider(): iterable
    {
        yield 'randonnée' => ['hike'];
        yield 'visite de ville' => ['city_visit'];
    }

    #[DataProvider('routeKindProvider')]
    public function testEmptyDraftCanReceiveItsFirstCoordinateFreeStep(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $client->loginUser($admin);

        $crawler = $client->request('GET', $this->editUrl($draft));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-route-step-empty]'));
        self::assertCount(1, $crawler->filter('[data-route-step-insertion]'));
        self::assertSame('1', $this->inputValue($crawler, '[data-route-step-insert-position]'));
        self::assertSame('Ajouter la première étape', trim($crawler->filter('[data-route-step-insert-label]')->text()));

        $client->request('POST', $this->addUrl($draft), [
            '_token' => $this->tokenFromFormAction($crawler, $this->addUrl($draft)),
            'position' => '1',
        ]);

        self::assertResponseRedirects();
        $steps = $this->orderedSteps($draft);
        self::assertCount(1, $steps);
        self::assertSame(1, $steps[0]->getPosition());
        self::assertNull($steps[0]->getLatitude());
        self::assertNull($steps[0]->getLongitude());
        self::assertNull($steps[0]->getAccuracy());
        self::assertTrue($steps[0]->hasInheritedCoordinates());
        if ($steps[0] instanceof HikePoint) {
            self::assertSame(HikePointType::Start, $steps[0]->getType());
        } else {
            self::assertSame(CityVisitPointType::Start, $steps[0]->getType());
        }
    }

    #[DataProvider('routeKindProvider')]
    public function testStepsCanBeAddedAtBeginningPositionTwoAndEnd(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 42.111111, 2.111111, 1);
        $second = $this->createRoutePoint($draft, 43.222222, 3.222222, 2);
        $this->markAsStart($first);
        $first
            ->setTitle('Départ conservé')
            ->setAccuracy(4.25)
            ->setCoordinatesInherited(false)
            ->setDetectedCommuneName('Commune du départ')
            ->setDetectedCommuneCode('00001');
        $second
            ->setTitle('Arrivée conservée')
            ->setAccuracy(8.5)
            ->setCoordinatesInherited(false);
        $this->persistAndFlush($first, $second);
        $firstId = $this->entityId($first);
        $secondId = $this->entityId($second);
        $client->loginUser($admin);

        $atBeginning = $this->submitAdd($client, $draft, 1);
        $atBeginningId = $this->entityId($atBeginning);
        self::assertSame(
            [$atBeginningId, $firstId, $secondId],
            $this->stepIds($this->orderedSteps($draft)),
        );
        self::assertSame(42.111111, $atBeginning->getLatitude());
        self::assertSame(2.111111, $atBeginning->getLongitude());
        self::assertSame(4.25, $atBeginning->getAccuracy());
        self::assertTrue($atBeginning->hasInheritedCoordinates());

        $atPositionTwo = $this->submitAdd($client, $draft, 2);
        $atPositionTwoId = $this->entityId($atPositionTwo);
        self::assertSame(
            [$atBeginningId, $atPositionTwoId, $firstId, $secondId],
            $this->stepIds($this->orderedSteps($draft)),
        );
        self::assertSame(42.111111, $atPositionTwo->getLatitude());
        self::assertSame(2.111111, $atPositionTwo->getLongitude());
        self::assertSame(4.25, $atPositionTwo->getAccuracy());

        $atEnd = $this->submitAdd($client, $draft, 5);
        $atEndId = $this->entityId($atEnd);
        $steps = $this->orderedSteps($draft);
        self::assertSame(
            [$atBeginningId, $atPositionTwoId, $firstId, $secondId, $atEndId],
            $this->stepIds($steps),
        );
        self::assertSame([1, 2, 3, 4, 5], array_map(
            static fn (HikePoint|CityVisitPoint $step): int => $step->getPosition(),
            $steps,
        ));
        self::assertSame(43.222222, $atEnd->getLatitude());
        self::assertSame(3.222222, $atEnd->getLongitude());
        self::assertSame(8.5, $atEnd->getAccuracy());

        $stepsById = $this->stepsById($steps);
        self::assertSame($this->gpsSnapshot($first), $this->gpsSnapshot($stepsById[$firstId]));
        self::assertSame($this->gpsSnapshot($second), $this->gpsSnapshot($stepsById[$secondId]));
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateInheritanceAcceptsZeroAndKeepsAnEmptyPredecessorEmpty(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $zeroDraft = $this->createRouteDraft($routeKind, $admin);
        $zeroSource = $this->createRoutePoint($zeroDraft, 0.0, 0.0, 1);
        $this->markAsStart($zeroSource);
        $zeroSource
            ->setAccuracy(0.0)
            ->setDetectedCommuneName('Méridien zéro')
            ->setDetectedCommuneCode('00000')
            ->setDetectedDepartmentName('Département zéro')
            ->setDetectedRegionName('Région zéro');
        $this->persistAndFlush($zeroSource);
        $client->loginUser($admin);

        $zeroInherited = $this->submitAdd($client, $zeroDraft, 2);

        self::assertSame(0.0, $zeroInherited->getLatitude());
        self::assertSame(0.0, $zeroInherited->getLongitude());
        self::assertSame(0.0, $zeroInherited->getAccuracy());
        self::assertSame('Méridien zéro', $zeroInherited->getDetectedCommuneName());
        self::assertSame('00000', $zeroInherited->getDetectedCommuneCode());
        self::assertSame('Département zéro', $zeroInherited->getDetectedDepartmentName());
        self::assertSame('Région zéro', $zeroInherited->getDetectedRegionName());

        $emptyDraft = $this->createRouteDraft($routeKind, $admin);
        $emptySource = $this->createRoutePoint($emptyDraft, 45.0, 5.0, 1);
        $this->markAsStart($emptySource);
        $emptySource
            ->setLatitude(null)
            ->setLongitude(null)
            ->setAccuracy(null)
            ->setDetectedCommuneName('Commune sans point GPS')
            ->setDetectedCommuneCode('00999');
        $this->persistAndFlush($emptySource);

        $emptyInherited = $this->submitAdd($client, $emptyDraft, 2);

        self::assertNull($emptyInherited->getLatitude());
        self::assertNull($emptyInherited->getLongitude());
        self::assertNull($emptyInherited->getAccuracy());
        self::assertTrue($emptyInherited->hasInheritedCoordinates());
        self::assertSame('Commune sans point GPS', $emptyInherited->getDetectedCommuneName());
        self::assertSame('00999', $emptyInherited->getDetectedCommuneCode());
    }

    #[DataProvider('routeKindProvider')]
    public function testValidReorderReturnsCanonicalResponseAndPreservesGpsData(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 0.0, 0.0, 1);
        $second = $this->createRoutePoint($draft, 42.123456, 2.654321, 2);
        $third = $this->createRoutePoint($draft, -12.75, 130.5, 3);
        $first
            ->setTitle('Zéro GPS')
            ->setNote('Note zéro conservée')
            ->setAccuracy(0.0)
            ->setCoordinatesInherited(false);
        $second
            ->setTitle('Point précis')
            ->setNote('Note précise conservée')
            ->setAccuracy(3.75)
            ->setCoordinatesInherited(false);
        $third
            ->setTitle('Point lointain')
            ->setNote('Note lointaine conservée')
            ->setAccuracy(18.25)
            ->setCoordinatesInherited(false);
        $this->persistAndFlush($first, $second, $third);
        $firstId = $this->entityId($first);
        $secondId = $this->entityId($second);
        $thirdId = $this->entityId($third);
        $snapshots = [
            $firstId => $this->gpsSnapshot($first),
            $secondId => $this->gpsSnapshot($second),
            $thirdId => $this->gpsSnapshot($third),
        ];
        $client->loginUser($admin);
        $orderedIds = [$thirdId, $firstId, $secondId];

        $this->submitReorder(
            $client,
            $draft,
            $orderedIds,
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'reorder')),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([
            'success' => true,
            'steps' => [
                ['id' => $thirdId, 'position' => 1],
                ['id' => $firstId, 'position' => 2],
                ['id' => $secondId, 'position' => 3],
            ],
            'gpsStates' => [
                [
                    'id' => $thirdId,
                    'latitude' => -12.75,
                    'longitude' => 130.5,
                    'accuracy' => 18.25,
                    'inherited' => false,
                ],
                [
                    'id' => $firstId,
                    'latitude' => 0,
                    'longitude' => 0,
                    'accuracy' => 0,
                    'inherited' => false,
                ],
                [
                    'id' => $secondId,
                    'latitude' => 42.123456,
                    'longitude' => 2.654321,
                    'accuracy' => 3.75,
                    'inherited' => false,
                ],
            ],
        ], $this->responsePayload($client));

        $steps = $this->orderedSteps($draft);
        self::assertSame($orderedIds, $this->stepIds($steps));
        self::assertSame([1, 2, 3], array_map(
            static fn (HikePoint|CityVisitPoint $step): int => $step->getPosition(),
            $steps,
        ));
        foreach ($steps as $step) {
            $id = $this->entityId($step);
            self::assertSame($snapshots[$id], $this->gpsSnapshot($step));
        }
    }

    #[DataProvider('routeKindProvider')]
    public function testReorderRefreshesOnlyInheritedGpsFromItsNewPredecessor(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $oldPredecessor = $this->createRoutePoint($draft, 41.111111, 1.111111, 1);
        $inherited = $this->createRoutePoint($draft, 41.111111, 1.111111, 2);
        $newPredecessor = $this->createRoutePoint($draft, 43.333333, 3.333333, 3);
        $this->markAsStart($oldPredecessor);
        $oldPredecessor
            ->setTitle('Ancien prédécesseur personnalisé')
            ->setAccuracy(4.0)
            ->setCoordinatesInherited(false);
        $inherited
            ->setTitle('Étape réellement héritée')
            ->setNote('Éditorial hérité à conserver')
            ->setAccuracy(4.0)
            ->setCoordinatesInherited(true)
            ->setDetectedCommuneName('Métadonnée propre à l’étape');
        $newPredecessor
            ->setTitle('Nouveau prédécesseur personnalisé')
            ->setAccuracy(7.5)
            ->setCoordinatesInherited(false);
        $this->persistAndFlush($oldPredecessor, $inherited, $newPredecessor);
        $oldPredecessorId = $this->entityId($oldPredecessor);
        $inheritedId = $this->entityId($inherited);
        $newPredecessorId = $this->entityId($newPredecessor);
        $oldPredecessorSnapshot = $this->gpsSnapshot($oldPredecessor);
        $newPredecessorSnapshot = $this->gpsSnapshot($newPredecessor);
        $client->loginUser($admin);

        $this->submitReorder(
            $client,
            $draft,
            [$newPredecessorId, $inheritedId, $oldPredecessorId],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'reorder')),
        );

        self::assertResponseIsSuccessful();
        $stepsById = $this->stepsById($this->orderedSteps($draft));
        self::assertSame($newPredecessorSnapshot, $this->gpsSnapshot($stepsById[$newPredecessorId]));
        self::assertSame($oldPredecessorSnapshot, $this->gpsSnapshot($stepsById[$oldPredecessorId]));

        $refreshedInherited = $stepsById[$inheritedId];
        self::assertSame(43.333333, $refreshedInherited->getLatitude());
        self::assertSame(3.333333, $refreshedInherited->getLongitude());
        self::assertSame(7.5, $refreshedInherited->getAccuracy());
        self::assertTrue($refreshedInherited->hasInheritedCoordinates());
        self::assertSame('Étape réellement héritée', $refreshedInherited->getTitle());
        self::assertSame('Éditorial hérité à conserver', $refreshedInherited->getNote());
        self::assertSame('Métadonnée propre à l’étape', $refreshedInherited->getDetectedCommuneName());
    }

    #[DataProvider('routeKindProvider')]
    public function testCustomizedGpsIsPersistedAtomicallyWithReorderAndStopsInheritance(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $oldPredecessor = $this->createRoutePoint($draft, 41.111111, 1.111111, 1);
        $customized = $this->createRoutePoint($draft, 41.111111, 1.111111, 2);
        $newPredecessor = $this->createRoutePoint($draft, 43.333333, 3.333333, 3);
        $this->markAsStart($oldPredecessor);
        $oldPredecessor->setAccuracy(4.0)->setCoordinatesInherited(false);
        $customized
            ->setTitle('Étape héritée personnalisée pendant le tri')
            ->setAccuracy(4.0)
            ->setCoordinatesInherited(true);
        $newPredecessor->setAccuracy(7.5)->setCoordinatesInherited(false);
        $this->persistAndFlush($oldPredecessor, $customized, $newPredecessor);
        $oldPredecessorId = $this->entityId($oldPredecessor);
        $customizedId = $this->entityId($customized);
        $newPredecessorId = $this->entityId($newPredecessor);
        $client->loginUser($admin);
        $expectedCoordinates = [
            'latitude' => 44.4444,
            'longitude' => 4.4444,
            'accuracy' => 1.25,
        ];

        $this->submitReorder(
            $client,
            $draft,
            [$newPredecessorId, $customizedId, $oldPredecessorId],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'reorder')),
            [[
                'id' => $customizedId,
                'latitude' => $expectedCoordinates['latitude'],
                'longitude' => $expectedCoordinates['longitude'],
                'accuracy' => $expectedCoordinates['accuracy'],
            ]],
        );

        self::assertResponseIsSuccessful();
        $steps = $this->orderedSteps($draft);
        self::assertSame(
            [$newPredecessorId, $customizedId, $oldPredecessorId],
            $this->stepIds($steps),
        );
        $customizedAfterReorder = $this->stepsById($steps)[$customizedId];
        self::assertSame($expectedCoordinates['latitude'], $customizedAfterReorder->getLatitude());
        self::assertSame($expectedCoordinates['longitude'], $customizedAfterReorder->getLongitude());
        self::assertSame($expectedCoordinates['accuracy'], $customizedAfterReorder->getAccuracy());
        self::assertFalse($customizedAfterReorder->hasInheritedCoordinates());
        self::assertSame('Étape héritée personnalisée pendant le tri', $customizedAfterReorder->getTitle());

        $payload = $this->responsePayload($client);
        $gpsStates = $payload['gpsStates'] ?? null;
        self::assertIsArray($gpsStates);
        self::assertSame([
            'id' => $customizedId,
            'latitude' => $expectedCoordinates['latitude'],
            'longitude' => $expectedCoordinates['longitude'],
            'accuracy' => $expectedCoordinates['accuracy'],
            'inherited' => false,
        ], $gpsStates[1] ?? null);
    }

    #[DataProvider('routeKindProvider')]
    public function testReorderRejectsInvalidAndForeignCustomizedGpsWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 41.1, 1.1, 1);
        $second = $this->createRoutePoint($draft, 42.2, 2.2, 2);
        $foreignDraft = $this->createRouteDraft($routeKind, $admin);
        $foreign = $this->createRoutePoint($foreignDraft, 50.5, 6.5, 1);
        $firstId = $this->entityId($first);
        $secondId = $this->entityId($second);
        $expectedIds = [$firstId, $secondId];
        $firstSnapshot = $this->gpsSnapshot($first);
        $secondSnapshot = $this->gpsSnapshot($second);
        $client->loginUser($admin);
        $token = $this->csrfTokenForClient($client, $this->tokenId($draft, 'reorder'));

        $this->submitReorder(
            $client,
            $draft,
            array_reverse($expectedIds),
            $token,
            [[
                'id' => $secondId,
                'latitude' => 91,
                'longitude' => 2.2,
                'accuracy' => 2.0,
            ]],
        );
        $this->assertJsonFailure($client, 400, 'latitude GPS personnalisée');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));

        $this->submitReorder(
            $client,
            $draft,
            array_reverse($expectedIds),
            $token,
            [[
                'id' => $secondId,
            ]],
        );
        $this->assertJsonFailure($client, 400, 'doit fournir id, latitude, longitude et accuracy');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));

        $this->submitReorder(
            $client,
            $draft,
            array_reverse($expectedIds),
            $token,
            [[
                'id' => $this->entityId($foreign),
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 3.0,
            ]],
        );
        $this->assertJsonFailure($client, 422, 'n’appartient pas');
        // Doctrine closes an EntityManager whose transaction rolled back;
        // assert persisted state through a fresh kernel/EntityManager.
        static::ensureKernelShutdown();
        static::bootKernel();
        $steps = $this->orderedSteps($draft);
        self::assertSame($expectedIds, $this->stepIds($steps));
        self::assertSame($firstSnapshot, $this->gpsSnapshot($steps[0]));
        self::assertSame($secondSnapshot, $this->gpsSnapshot($steps[1]));
    }

    #[DataProvider('routeKindProvider')]
    public function testPointUpdateNormalizesAccuracyWhenCoordinatesAreCleared(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.5, 2.5, 1)->setAccuracy(8.5);
        $client->loginUser($admin);

        $client->request('POST', $this->updateUrl($point), [
            '_token' => $this->csrfTokenForClient($client, $this->tokenId($draft, 'update', $point)),
            'title' => 'Point sans GPS',
            'note' => 'Coordonnées retirées explicitement',
            'type' => $point->getType()->value,
            'latitude' => '',
            'longitude' => '',
            'accuracy' => '99',
            'coordinatesInherited' => '0',
        ]);

        self::assertResponseRedirects();
        self::assertNull($point->getLatitude());
        self::assertNull($point->getLongitude());
        self::assertNull($point->getAccuracy());
        self::assertFalse($point->hasInheritedCoordinates());
        self::assertSame('Point sans GPS', $point->getTitle());
        self::assertSame('Coordonnées retirées explicitement', $point->getNote());
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointUpdatesOnlyTargetGpsAndPreservesRouteState(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 41.1111111, 1.1111111, 1);
        $target = $this->createRoutePoint($draft, 42.2222222, 2.2222222, 2);
        $last = $this->createRoutePoint($draft, 43.3333333, 3.3333333, 3);
        $this->markAsStart($first);
        $first
            ->setTitle('Départ inchangé')
            ->setNote('Note du départ inchangée')
            ->setAccuracy(4.5)
            ->setCoordinatesInherited(false);
        $target
            ->setTitle('Belvédère éditorial conservé')
            ->setNote('La note riche de cette étape doit rester intacte.')
            ->setAccuracy(18.75)
            ->setCoordinatesInherited(true)
            ->setDetectedCommuneName('Commune conservée')
            ->setDetectedCommuneCode('66001')
            ->setDetectedDepartmentName('Département conservé')
            ->setDetectedRegionName('Région conservée');
        if ($target instanceof HikePoint) {
            $target->setType(HikePointType::Viewpoint);
        } else {
            $target->setType(CityVisitPointType::Museum);
        }
        $last
            ->setTitle('Arrivée inchangée')
            ->setNote('Note de l’arrivée inchangée')
            ->setAccuracy(9.25)
            ->setCoordinatesInherited(false);
        $media = $this->createImageMedia('Média de l’étape GPS conservé');
        $mediaLink = $this->attachPointMedia($target, $media);
        $this->persistAndFlush($first, $target, $last, $mediaLink);

        $firstId = $this->entityId($first);
        $targetId = $this->entityId($target);
        $lastId = $this->entityId($last);
        $mediaId = $media->getId();
        $mediaLinkId = $mediaLink->getId();
        self::assertNotNull($mediaId);
        self::assertNotNull($mediaLinkId);
        $expectedOrder = [$firstId, $targetId, $lastId];
        $firstSnapshot = $this->pointSnapshot($first);
        $lastSnapshot = $this->pointSnapshot($last);
        $expectedTargetEditorial = [
            'position' => $target->getPosition(),
            'type' => $target->getType()->value,
            'title' => $target->getTitle(),
            'note' => $target->getNote(),
            'communeName' => $target->getDetectedCommuneName(),
            'communeCode' => $target->getDetectedCommuneCode(),
            'departmentName' => $target->getDetectedDepartmentName(),
            'regionName' => $target->getDetectedRegionName(),
        ];
        $client->loginUser($admin);
        $expectedLatitude = 42.6178457;
        $expectedLongitude = 2.4213764;
        $expectedAccuracy = 3.25;

        $this->submitCoordinates(
            $client,
            $draft,
            $target,
            [
                'latitude' => '42.6178457',
                'longitude' => '2.4213764',
                'accuracy' => '3.25',
                ...$this->coordinatePrecondition($target),
            ],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $target)),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([
            'success' => true,
            'message' => 'La position GPS de l’étape a été enregistrée.',
            'primaryLocationStepId' => $firstId,
            'step' => [
                'id' => $targetId,
                'position' => 2,
                'latitude' => $expectedLatitude,
                'longitude' => $expectedLongitude,
                'accuracy' => $expectedAccuracy,
                'coordinatesInherited' => false,
            ],
        ], $this->responsePayload($client));

        $steps = $this->orderedSteps($draft);
        self::assertSame($expectedOrder, $this->stepIds($steps));
        self::assertSame([1, 2, 3], array_map(
            static fn (HikePoint|CityVisitPoint $step): int => $step->getPosition(),
            $steps,
        ));
        $stepsById = $this->stepsById($steps);
        self::assertSame($firstSnapshot, $this->pointSnapshot($stepsById[$firstId]));
        self::assertSame($lastSnapshot, $this->pointSnapshot($stepsById[$lastId]));

        $updatedTarget = $stepsById[$targetId];
        self::assertEqualsWithDelta($expectedLatitude, (float) $updatedTarget->getLatitude(), 0.00000001);
        self::assertEqualsWithDelta($expectedLongitude, (float) $updatedTarget->getLongitude(), 0.00000001);
        self::assertEqualsWithDelta($expectedAccuracy, (float) $updatedTarget->getAccuracy(), 0.00000001);
        self::assertFalse($updatedTarget->hasInheritedCoordinates());
        self::assertSame($expectedTargetEditorial, [
            'position' => $updatedTarget->getPosition(),
            'type' => $updatedTarget->getType()->value,
            'title' => $updatedTarget->getTitle(),
            'note' => $updatedTarget->getNote(),
            'communeName' => $updatedTarget->getDetectedCommuneName(),
            'communeCode' => $updatedTarget->getDetectedCommuneCode(),
            'departmentName' => $updatedTarget->getDetectedDepartmentName(),
            'regionName' => $updatedTarget->getDetectedRegionName(),
        ]);

        $persistedLink = $this->entityManager()->find($mediaLink::class, $mediaLinkId);
        self::assertInstanceOf($mediaLink::class, $persistedLink);
        self::assertSame($targetId, $persistedLink->getPoint()?->getId());
        self::assertSame($mediaId, $persistedLink->getMediaAsset()?->getId());
        self::assertSame('Média de l’étape GPS conservé', $persistedLink->getMediaAsset()?->getTitle());
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointCanSetFirstCoordinatesOnEmptyStep(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.5, 2.5, 1);
        $point
            ->setTitle('Étape initialement vide')
            ->setNote('Éditorial sans coordonnées')
            ->setLatitude(null)
            ->setLongitude(null)
            ->setAccuracy(null)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $pointId = $this->entityId($point);
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $point,
            [
                'latitude' => 0,
                'longitude' => 0,
                'accuracy' => 0,
                ...$this->coordinatePrecondition($point),
            ],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $point)),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([
            'success' => true,
            'message' => 'La position GPS de l’étape a été enregistrée.',
            'primaryLocationStepId' => $pointId,
            'step' => [
                'id' => $pointId,
                'position' => 1,
                'latitude' => 0,
                'longitude' => 0,
                'accuracy' => 0,
                'coordinatesInherited' => false,
            ],
        ], $this->responsePayload($client));
        $steps = $this->orderedSteps($draft);
        self::assertSame([$pointId], $this->stepIds($steps));
        self::assertSame(1, $steps[0]->getPosition());
        self::assertSame(0.0, $steps[0]->getLatitude());
        self::assertSame(0.0, $steps[0]->getLongitude());
        self::assertSame(0.0, $steps[0]->getAccuracy());
        self::assertFalse($steps[0]->hasInheritedCoordinates());
        self::assertSame('Étape initialement vide', $steps[0]->getTitle());
        self::assertSame('Éditorial sans coordonnées', $steps[0]->getNote());
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRequiresConcurrencyPreconditionWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $expectedSnapshot = $this->pointSnapshot($point);
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $point,
            ['latitude' => 45.5, 'longitude' => 5.5, 'accuracy' => 2.0],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $point)),
        );

        $this->assertJsonFailure($client, 400, 'expectedPosition', 'invalid_payload');
        self::assertSame($expectedSnapshot, $this->pointSnapshot($this->orderedSteps($draft)[0]));
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRejectsStaleExpectedPositionWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 41.1234567, 1.7654321, 1);
        $target = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 2)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($target);
        $firstId = $this->entityId($first);
        $targetId = $this->entityId($target);
        $firstSnapshot = $this->pointSnapshot($first);
        $targetSnapshot = $this->pointSnapshot($target);
        $precondition = $this->coordinatePrecondition($target);
        $precondition['expectedPosition'] = 1;
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $target,
            [
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 2.0,
                ...$precondition,
            ],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $target)),
        );

        $this->assertJsonFailure($client, 409, 'ordre du parcours', 'stale_state');
        // Doctrine ferme l’EntityManager après le rollback transactionnel.
        static::ensureKernelShutdown();
        static::bootKernel();
        $steps = $this->orderedSteps($draft);
        self::assertSame([$firstId, $targetId], $this->stepIds($steps));
        self::assertSame($firstSnapshot, $this->pointSnapshot($steps[0]));
        self::assertSame($targetSnapshot, $this->pointSnapshot($steps[1]));
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRejectsStaleExpectedCoordinatesWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $pointId = $this->entityId($point);
        $expectedSnapshot = $this->pointSnapshot($point);
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $point,
            [
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 2.0,
                'expectedPosition' => 1,
                'expectedCoordinates' => [
                    'latitude' => 40.0,
                    'longitude' => 1.0,
                    'accuracy' => 5.0,
                    'coordinatesInherited' => false,
                ],
            ],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $point)),
        );

        $this->assertJsonFailure($client, 409, 'position GPS', 'stale_state');
        // Doctrine ferme l’EntityManager après le rollback transactionnel.
        static::ensureKernelShutdown();
        static::bootKernel();
        $persistedPoint = $this->stepsById($this->orderedSteps($draft))[$pointId];
        self::assertSame($expectedSnapshot, $this->pointSnapshot($persistedPoint));
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointAcceptsIdempotentRetryWithStaleExpectedCoordinates(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $stalePrecondition = $this->coordinatePrecondition($point);
        $point
            ->setLatitude(45.5)
            ->setLongitude(5.5)
            ->setAccuracy(2.0)
            ->setCoordinatesInherited(false);
        $this->persistAndFlush($point);
        $pointId = $this->entityId($point);
        $currentSnapshot = $this->pointSnapshot($point);
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $point,
            [
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 2.0,
                ...$stalePrecondition,
            ],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $point)),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([
            'success' => true,
            'message' => 'La position GPS de l’étape a été enregistrée.',
            'primaryLocationStepId' => $pointId,
            'step' => [
                'id' => $pointId,
                'position' => 1,
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 2,
                'coordinatesInherited' => false,
            ],
        ], $this->responsePayload($client));
        self::assertSame($currentSnapshot, $this->pointSnapshot($this->orderedSteps($draft)[0]));
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRejectsInvalidOrIncompleteCoordinatesWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1);
        $point
            ->setTitle('Étape protégée des coordonnées invalides')
            ->setNote('Cette note ne bouge pas')
            ->setAccuracy(6.5)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $pointId = $this->entityId($point);
        $expectedSnapshot = $this->pointSnapshot($point);
        $client->loginUser($admin);
        $token = $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $point));
        $precondition = $this->coordinatePrecondition($point);
        $invalidPayloads = [
            'latitude hors limites' => [[
                'latitude' => 90.0001,
                'longitude' => 2.5,
                ...$precondition,
            ], 422, 'latitude GPS'],
            'longitude hors limites' => [[
                'latitude' => 42.5,
                'longitude' => 180.0001,
                ...$precondition,
            ], 422, 'longitude GPS'],
            'latitude non numérique' => [[
                'latitude' => 'nord',
                'longitude' => 2.5,
                ...$precondition,
            ], 422, 'latitude GPS'],
            'longitude absente' => [[
                'latitude' => 42.5,
                ...$precondition,
            ], 400, 'uniquement latitude'],
            'latitude vide' => [[
                'latitude' => null,
                'longitude' => 2.5,
                ...$precondition,
            ], 422, 'doivent être renseignées'],
            'champ métier inattendu' => [[
                'latitude' => 42.5,
                'longitude' => 2.5,
                'position' => 9,
                ...$precondition,
            ], 400, 'uniquement latitude'],
        ];

        foreach ($invalidPayloads as $case => [$payload, $status, $errorContains]) {
            $this->submitCoordinates($client, $draft, $point, $payload, $token);
            $this->assertJsonFailure($client, $status, $errorContains);
            self::assertSame(
                $expectedSnapshot,
                $this->pointSnapshot($this->stepsById($this->orderedSteps($draft))[$pointId]),
                sprintf('Le cas « %s » ne doit rien modifier.', $case),
            );
        }
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRejectsInvalidAccuracyWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $pointId = $this->entityId($point);
        $expectedSnapshot = $this->pointSnapshot($point);
        $client->loginUser($admin);
        $token = $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $point));

        foreach ([-0.01, 'imprécise', true] as $invalidAccuracy) {
            $this->submitCoordinates($client, $draft, $point, [
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => $invalidAccuracy,
                ...$this->coordinatePrecondition($point),
            ], $token);
            $this->assertJsonFailure($client, 422, 'précision GPS');
            self::assertSame(
                $expectedSnapshot,
                $this->pointSnapshot($this->stepsById($this->orderedSteps($draft))[$pointId]),
            );
        }
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRejectsBadCsrfWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($point);
        $expectedSnapshot = $this->pointSnapshot($point);
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $point,
            [
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 2.0,
                ...$this->coordinatePrecondition($point),
            ],
            'mauvais-jeton',
        );

        $this->assertJsonFailure($client, 403, 'jeton de sécurité');
        self::assertSame($expectedSnapshot, $this->pointSnapshot($this->orderedSteps($draft)[0]));
    }

    #[DataProvider('routeKindProvider')]
    public function testCoordinateEndpointRejectsPointFromAnotherDraftWithoutMutation(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $localPoint = $this->createRoutePoint($draft, 42.1234567, 2.7654321, 1)
            ->setAccuracy(7.25)
            ->setCoordinatesInherited(false);
        $foreignDraft = $this->createRouteDraft($routeKind, $admin);
        $foreignPoint = $this->createRoutePoint($foreignDraft, 48.1234567, 6.7654321, 1)
            ->setAccuracy(12.5)
            ->setCoordinatesInherited(true);
        $this->persistAndFlush($localPoint, $foreignPoint);
        $localSnapshot = $this->pointSnapshot($localPoint);
        $foreignSnapshot = $this->pointSnapshot($foreignPoint);
        $client->loginUser($admin);

        $this->submitCoordinates(
            $client,
            $draft,
            $foreignPoint,
            [
                'latitude' => 45.5,
                'longitude' => 5.5,
                'accuracy' => 2.0,
                ...$this->coordinatePrecondition($foreignPoint),
            ],
            $this->csrfTokenForClient($client, $this->tokenId($draft, 'coordinates', $foreignPoint)),
        );

        $this->assertJsonFailure($client, 404, 'introuvable');
        self::assertSame($localSnapshot, $this->pointSnapshot($this->orderedSteps($draft)[0]));
        self::assertSame($foreignSnapshot, $this->pointSnapshot($this->orderedSteps($foreignDraft)[0]));
    }

    #[DataProvider('routeKindProvider')]
    public function testReorderRejectsBadCsrfDuplicateIncompleteForeignAndUnknownIds(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 41.1, 1.1, 1);
        $second = $this->createRoutePoint($draft, 42.2, 2.2, 2);
        $third = $this->createRoutePoint($draft, 43.3, 3.3, 3);
        $foreignDraft = $this->createRouteDraft($routeKind, $admin);
        $foreign = $this->createRoutePoint($foreignDraft, 50.0, 6.0, 1);
        $expectedIds = [
            $this->entityId($first),
            $this->entityId($second),
            $this->entityId($third),
        ];
        $client->loginUser($admin);
        $validToken = $this->csrfTokenForClient($client, $this->tokenId($draft, 'reorder'));

        $this->submitReorder($client, $draft, array_reverse($expectedIds), 'mauvais-jeton');
        $this->assertJsonFailure($client, 403, 'jeton de sécurité');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));

        $this->submitReorder(
            $client,
            $draft,
            [$expectedIds[0], $expectedIds[0], $expectedIds[2]],
            $validToken,
        );
        $this->assertJsonFailure($client, 422, 'doublon');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));

        $this->submitReorder($client, $draft, [$expectedIds[2], $expectedIds[0]], $validToken);
        $this->assertJsonFailure($client, 422, 'exactement toutes les étapes');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));

        $this->submitReorder(
            $client,
            $draft,
            [$expectedIds[0], $expectedIds[1], $this->entityId($foreign)],
            $validToken,
        );
        $this->assertJsonFailure($client, 422, 'n’appartient pas');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));

        $this->submitReorder(
            $client,
            $draft,
            [$expectedIds[0], $expectedIds[1], 2_147_483_647],
            $validToken,
        );
        $this->assertJsonFailure($client, 422, 'n’appartient pas');
        self::assertSame($expectedIds, $this->stepIds($this->orderedSteps($draft)));
        self::assertSame([1], array_map(
            static fn (HikePoint|CityVisitPoint $step): int => $step->getPosition(),
            $this->orderedSteps($foreignDraft),
        ));
    }

    #[DataProvider('routeKindProvider')]
    public function testDeleteCompactsPositionsWithoutChangingRemainingGps(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $first = $this->createRoutePoint($draft, 0.0, 0.0, 1);
        $deleted = $this->createRoutePoint($draft, 42.2, 2.2, 2);
        $last = $this->createRoutePoint($draft, 43.3, 3.3, 3);
        $first->setAccuracy(0.0)->setCoordinatesInherited(false);
        $last->setAccuracy(9.5)->setCoordinatesInherited(true);
        $this->persistAndFlush($first, $last);
        $firstId = $this->entityId($first);
        $deletedId = $this->entityId($deleted);
        $lastId = $this->entityId($last);
        $firstSnapshot = $this->gpsSnapshot($first);
        $lastSnapshot = $this->gpsSnapshot($last);
        $client->loginUser($admin);

        $client->request('POST', $this->deleteUrl($deleted), [
            '_token' => $this->csrfTokenForClient($client, $this->tokenId($draft, 'delete', $deleted)),
        ]);

        self::assertResponseRedirects($this->editUrl($draft).'#section-points');
        self::assertNull($this->entityManager()->find($deleted::class, $deletedId));
        $steps = $this->orderedSteps($draft);
        self::assertSame([$firstId, $lastId], $this->stepIds($steps));
        self::assertSame([1, 2], array_map(
            static fn (HikePoint|CityVisitPoint $step): int => $step->getPosition(),
            $steps,
        ));
        self::assertSame($firstSnapshot, $this->gpsSnapshot($steps[0]));
        self::assertSame($lastSnapshot, $this->gpsSnapshot($steps[1]));
    }

    #[DataProvider('routeKindProvider')]
    public function testEveryRouteStepMutationRequiresAdminAccess(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $regularUser = $this->createUser();
        $unverifiedAdmin = $this->createUnverifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $point = $this->createRoutePoint($draft, 42.5, 2.5, 1);
        $mutationUrls = [
            $this->addUrl($draft),
            $this->updateUrl($point),
            $this->reorderUrl($draft),
            $this->coordinatesUrl($draft, $point),
            $this->deleteUrl($point),
        ];

        foreach ($mutationUrls as $url) {
            $client->request('POST', $url);
            self::assertResponseRedirects('/login');
        }

        $client->loginUser($regularUser);
        foreach ($mutationUrls as $url) {
            $client->request('POST', $url);
            self::assertResponseRedirects('/');
        }

        $client->loginUser($unverifiedAdmin);
        foreach ($mutationUrls as $url) {
            $client->request('POST', $url);
            self::assertResponseRedirects('/');
        }

        $steps = $this->orderedSteps($draft);
        self::assertSame([$this->entityId($point)], $this->stepIds($steps));
        self::assertSame(1, $steps[0]->getPosition());
        self::assertSame(42.5, $steps[0]->getLatitude());
        self::assertSame(2.5, $steps[0]->getLongitude());
    }

    #[DataProvider('routeKindProvider')]
    public function testTwigRendersStepsAndOrderingControlsInCanonicalOrder(string $routeKind): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $draft = $this->createRouteDraft($routeKind, $admin);
        $createdThird = $this->createRoutePoint($draft, 43.3, 3.3, 3)->setTitle('Troisième');
        $createdFirst = $this->createRoutePoint($draft, 41.1, 1.1, 1)->setTitle('Première');
        $createdSecond = $this->createRoutePoint($draft, 42.2, 2.2, 2)->setTitle('Deuxième');
        $createdFirst->setAccuracy(4.5)->setCoordinatesInherited(false);
        $createdSecond->setAccuracy(7.25)->setCoordinatesInherited(true);
        $createdThird->setAccuracy(null)->setCoordinatesInherited(false);
        $this->persistAndFlush($createdThird, $createdFirst, $createdSecond);
        $client->loginUser($admin);
        // Reload the association as an actual HTTP request would so Doctrine's
        // position ASC ordering, rather than fixture insertion order, is used.
        $this->entityManager()->clear();

        $crawler = $client->request('GET', $this->editUrl($draft));

        self::assertResponseIsSuccessful();
        $ordering = $crawler->filter('[data-route-step-ordering]');
        self::assertCount(1, $ordering);
        self::assertSame($this->reorderUrl($draft), $ordering->attr('data-reorder-url'));
        self::assertSame((string) $this->entityId($createdFirst), $ordering->attr('data-primary-step-id'));
        $reorderToken = $ordering->attr('data-reorder-token');
        self::assertIsString($reorderToken);
        self::assertNotSame('', $reorderToken);

        $insertionForms = $ordering->filter('[data-route-step-insertion]');
        self::assertCount(4, $insertionForms);
        self::assertSame(['1', '2', '3', '4'], $insertionForms->each(
            static fn (Crawler $form): string => (string) $form->filter('[data-route-step-insert-position]')->attr('value'),
        ));
        self::assertSame([
            'Ajouter au début',
            'Ajouter après l’étape 1',
            'Ajouter après l’étape 2',
            'Ajouter à la fin',
        ], $insertionForms->each(
            static fn (Crawler $form): string => trim($form->filter('[data-route-step-insert-label]')->text()),
        ));

        $steps = $ordering->filter('[data-route-step]');
        self::assertCount(3, $steps);
        self::assertSame([
            (string) $this->entityId($createdFirst),
            (string) $this->entityId($createdSecond),
            (string) $this->entityId($createdThird),
        ], $steps->each(static fn (Crawler $step): string => (string) $step->attr('data-step-id')));
        self::assertSame(['1', '2', '3'], $steps->each(
            static fn (Crawler $step): string => (string) $step->attr('data-step-position'),
        ));
        self::assertSame(
            $steps->each(static fn (Crawler $step): string => (string) $step->attr('data-step-type')),
            $steps->each(static fn (Crawler $step): string => (string) $step->attr('data-persisted-step-type')),
        );
        self::assertSame(['Première', 'Deuxième', 'Troisième'], $steps->each(
            static fn (Crawler $step): string => trim($step->filter('[data-route-step-name]')->text()),
        ));

        self::assertCount(3, $ordering->filter('[data-route-step-drag-handle]'));
        self::assertCount(3, $ordering->filter('[data-route-step-up]'));
        self::assertCount(3, $ordering->filter('[data-route-step-down]'));
        self::assertCount(3, $ordering->filter('[data-route-step-copy-previous]'));
        self::assertCount(3, $ordering->filter('[data-high-precision-gps]'));
        $adjustButtons = $ordering->filter('[data-route-step-adjust-position]');
        self::assertCount(3, $adjustButtons);
        self::assertCount(3, $ordering->filter('form[data-route-step-form]'));
        self::assertCount(3, $ordering->filter('form.route-step__delete-form'));
        self::assertCount(1, $steps->eq(0)->filter('[data-route-step-up][disabled]'));
        self::assertCount(0, $steps->eq(0)->filter('[data-route-step-down][disabled]'));
        self::assertCount(0, $steps->eq(2)->filter('[data-route-step-up][disabled]'));
        self::assertCount(1, $steps->eq(2)->filter('[data-route-step-down][disabled]'));

        $canonicalPoints = [$createdFirst, $createdSecond, $createdThird];
        $expectedInherited = ['false', 'true', 'false'];
        $editor = $ordering->filter('[data-route-step-position-editor]');
        self::assertCount(1, $editor);
        self::assertCount(1, $editor->filter('[data-location-picker][data-location-picker-mode="route_step"]'));
        self::assertCount(1, $editor->filter('[data-map-container]'));
        self::assertCount(1, $editor->filter('[data-validate-point]'));
        self::assertSame('Enregistrer cette position', trim($editor->filter('[data-validate-point]')->text()));
        self::assertCount(1, $editor->filter('[data-route-step-position-editor-close]'));
        self::assertTrue($editor->matches('[hidden]'));
        self::assertCount(0, $steps->filter('[data-location-picker]'));
        $editorId = $editor->attr('id');
        self::assertIsString($editorId);
        self::assertNotSame('', $editorId);
        $renderedCoordinateTokens = [];

        foreach ($canonicalPoints as $index => $point) {
            $button = $adjustButtons->eq($index);
            self::assertSame('Ajuster la position', trim($button->text()));
            self::assertSame($routeKind, $button->attr('data-route-kind'));
            self::assertSame((string) $this->entityId($draft), $button->attr('data-draft-id'));
            self::assertSame((string) $this->entityId($point), $button->attr('data-step-id'));
            self::assertSame((float) $point->getLatitude(), (float) $button->attr('data-latitude'));
            self::assertSame((float) $point->getLongitude(), (float) $button->attr('data-longitude'));
            self::assertSame($point->getAccuracy(), $button->attr('data-accuracy') === ''
                ? null
                : (float) $button->attr('data-accuracy'));
            self::assertSame($expectedInherited[$index], $button->attr('data-coordinates-inherited'));
            self::assertSame($this->coordinatesUrl($draft, $point), $button->attr('data-coordinates-url'));
            $renderedToken = $button->attr('data-coordinates-token');
            self::assertIsString($renderedToken);
            self::assertNotSame('', $renderedToken);
            $renderedCoordinateTokens[] = $renderedToken;
            self::assertSame(sprintf('Ajuster la position GPS de l’étape %d', $index + 1), $button->attr('aria-label'));
            self::assertSame($editorId, $button->attr('aria-controls'));
            self::assertSame('false', $button->attr('aria-expanded'));
        }

        foreach ($steps as $stepNode) {
            $step = new Crawler($stepNode);
            $controlOrder = [];
            foreach ($step->filter('.route-step__order-actions > button') as $buttonNode) {
                self::assertInstanceOf(\DOMElement::class, $buttonNode);
                self::assertNotSame('', trim($buttonNode->getAttribute('aria-label')));
                if ($buttonNode->hasAttribute('data-route-step-drag-handle')) {
                    $controlOrder[] = 'drag';
                } elseif ($buttonNode->hasAttribute('data-route-step-up')) {
                    $controlOrder[] = 'up';
                } elseif ($buttonNode->hasAttribute('data-route-step-down')) {
                    $controlOrder[] = 'down';
                }
            }
            self::assertSame(['drag', 'up', 'down'], $controlOrder);
        }

        $this->submitCoordinates(
            $client,
            $draft,
            $createdSecond,
            [
                'latitude' => (float) $createdSecond->getLatitude(),
                'longitude' => (float) $createdSecond->getLongitude(),
                'accuracy' => $createdSecond->getAccuracy(),
                ...$this->coordinatePrecondition($createdSecond),
            ],
            $renderedCoordinateTokens[1],
        );
        self::assertResponseIsSuccessful();
        $persistedSecond = $this->stepsById($this->orderedSteps($draft))[$this->entityId($createdSecond)];
        self::assertFalse($persistedSecond->hasInheritedCoordinates());
    }

    private function createRouteDraft(string $routeKind, User $admin): HikeDraft|CityVisitDraft
    {
        if ($routeKind === 'hike') {
            return $this->createHikeDraft($admin);
        }

        self::assertSame('city_visit', $routeKind);

        return $this->createCityVisitDraft($admin);
    }

    private function createRoutePoint(
        HikeDraft|CityVisitDraft $draft,
        float $latitude,
        float $longitude,
        int $position,
    ): HikePoint|CityVisitPoint {
        if ($draft instanceof HikeDraft) {
            return $this->createHikePoint($draft, $latitude, $longitude, $position);
        }

        return $this->createCityVisitPoint($draft, $latitude, $longitude, $position);
    }

    private function markAsStart(HikePoint|CityVisitPoint $point): void
    {
        if ($point instanceof HikePoint) {
            $point->setType(HikePointType::Start);

            return;
        }

        $point->setType(CityVisitPointType::Start);
    }

    private function submitAdd(
        KernelBrowser $client,
        HikeDraft|CityVisitDraft $draft,
        int $position,
    ): HikePoint|CityVisitPoint {
        $knownIds = $this->stepIds($this->orderedSteps($draft));
        $client->request('POST', $this->addUrl($draft), [
            '_token' => $this->csrfTokenForClient($client, $this->tokenId($draft, 'add')),
            'position' => (string) $position,
        ]);
        self::assertResponseRedirects();

        $addedSteps = array_values(array_filter(
            $this->orderedSteps($draft),
            fn (HikePoint|CityVisitPoint $step): bool => !in_array($this->entityId($step), $knownIds, true),
        ));
        self::assertCount(1, $addedSteps);

        return $addedSteps[0];
    }

    /**
     * @param list<int> $orderedIds
     * @param list<array<string, bool|float|int|string|null>> $customizedGps
     */
    private function submitReorder(
        KernelBrowser $client,
        HikeDraft|CityVisitDraft $draft,
        array $orderedIds,
        string $csrfToken,
        array $customizedGps = [],
    ): void {
        $client->request(
            'POST',
            $this->reorderUrl($draft),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
            json_encode([
                'orderedIds' => $orderedIds,
                'customizedGps' => $customizedGps,
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $payload */
    private function submitCoordinates(
        KernelBrowser $client,
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $point,
        array $payload,
        string $csrfToken,
    ): void {
        $client->request(
            'POST',
            $this->coordinatesUrl($draft, $point),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{
     *     expectedPosition: int,
     *     expectedCoordinates: array{
     *         latitude: float|null,
     *         longitude: float|null,
     *         accuracy: float|null,
     *         coordinatesInherited: bool
     *     }
     * }
     */
    private function coordinatePrecondition(HikePoint|CityVisitPoint $point): array
    {
        return [
            'expectedPosition' => $point->getPosition(),
            'expectedCoordinates' => [
                'latitude' => $point->getLatitude(),
                'longitude' => $point->getLongitude(),
                'accuracy' => $point->getAccuracy(),
                'coordinatesInherited' => $point->hasInheritedCoordinates(),
            ],
        ];
    }

    private function attachPointMedia(
        HikePoint|CityVisitPoint $point,
        MediaAsset $media,
    ): HikePointMedia|CityVisitPointMedia {
        if ($point instanceof HikePoint) {
            $link = (new HikePointMedia())
                ->setHikePoint($point)
                ->setMediaAsset($media);
            $point->addMediaLink($link);

            return $link;
        }

        $link = (new CityVisitPointMedia())
            ->setCityVisitPoint($point)
            ->setMediaAsset($media);
        $point->addMediaLink($link);

        return $link;
    }

    private function assertJsonFailure(
        KernelBrowser $client,
        int $status,
        string $errorContains,
        ?string $expectedCode = null,
    ): void
    {
        self::assertResponseStatusCodeSame($status);
        $payload = $this->responsePayload($client);
        self::assertFalse($payload['success'] ?? true);
        self::assertIsString($payload['error'] ?? null);
        self::assertStringContainsStringIgnoringCase($errorContains, $payload['error']);
        if ($expectedCode !== null) {
            self::assertSame($expectedCode, $payload['code'] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function responsePayload(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @return list<HikePoint|CityVisitPoint> */
    private function orderedSteps(HikeDraft|CityVisitDraft $draft): array
    {
        $pointClass = $draft instanceof HikeDraft ? HikePoint::class : CityVisitPoint::class;
        $association = $draft instanceof HikeDraft ? 'hikeDraft' : 'cityVisitDraft';
        $steps = $this->entityManager()->getRepository($pointClass)->findBy(
            [$association => $draft],
            ['position' => 'ASC'],
        );

        foreach ($steps as $step) {
            self::assertInstanceOf($pointClass, $step);
        }

        /** @var list<HikePoint|CityVisitPoint> $steps */
        return $steps;
    }

    /**
     * @param list<HikePoint|CityVisitPoint> $steps
     *
     * @return list<int>
     */
    private function stepIds(array $steps): array
    {
        return array_map($this->entityId(...), $steps);
    }

    /**
     * @param list<HikePoint|CityVisitPoint> $steps
     *
     * @return array<int, HikePoint|CityVisitPoint>
     */
    private function stepsById(array $steps): array
    {
        $byId = [];
        foreach ($steps as $step) {
            $byId[$this->entityId($step)] = $step;
        }

        return $byId;
    }

    /** @return array<string, bool|float|string|null> */
    private function gpsSnapshot(HikePoint|CityVisitPoint $point): array
    {
        return [
            'latitude' => $point->getLatitude(),
            'longitude' => $point->getLongitude(),
            'accuracy' => $point->getAccuracy(),
            'coordinatesInherited' => $point->hasInheritedCoordinates(),
            'title' => $point->getTitle(),
            'note' => $point->getNote(),
            'communeName' => $point->getDetectedCommuneName(),
            'communeCode' => $point->getDetectedCommuneCode(),
            'departmentName' => $point->getDetectedDepartmentName(),
            'regionName' => $point->getDetectedRegionName(),
        ];
    }

    /** @return array<string, bool|float|int|string|null> */
    private function pointSnapshot(HikePoint|CityVisitPoint $point): array
    {
        return [
            'position' => $point->getPosition(),
            'type' => $point->getType()->value,
            ...$this->gpsSnapshot($point),
        ];
    }

    private function entityId(HikeDraft|CityVisitDraft|HikePoint|CityVisitPoint $entity): int
    {
        $id = $entity->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function tokenId(
        HikeDraft|CityVisitDraft $draft,
        string $action,
        HikePoint|CityVisitPoint|null $point = null,
    ): string {
        $prefix = $draft instanceof HikeDraft ? 'studio_hike_point_' : 'studio_city_visit_point_';

        return $prefix.$action.'_'.($point === null ? $this->entityId($draft) : $this->entityId($point));
    }

    private function editUrl(HikeDraft|CityVisitDraft $draft): string
    {
        if ($draft instanceof HikeDraft) {
            return sprintf('/admin/studio/hikes/%d/edit', $this->entityId($draft));
        }

        return sprintf('/admin/studio/city-visits/%d/edit', $this->entityId($draft));
    }

    private function addUrl(HikeDraft|CityVisitDraft $draft): string
    {
        if ($draft instanceof HikeDraft) {
            return sprintf('/admin/studio/hikes/%d/points/add', $this->entityId($draft));
        }

        return sprintf('/admin/studio/city-visits/%d/points/add', $this->entityId($draft));
    }

    private function reorderUrl(HikeDraft|CityVisitDraft $draft): string
    {
        if ($draft instanceof HikeDraft) {
            return sprintf('/admin/studio/hikes/%d/points/reorder', $this->entityId($draft));
        }

        return sprintf('/admin/studio/city-visits/%d/points/reorder', $this->entityId($draft));
    }

    private function coordinatesUrl(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $point,
    ): string {
        if ($draft instanceof HikeDraft) {
            self::assertInstanceOf(HikePoint::class, $point);

            return sprintf(
                '/admin/studio/hikes/%d/steps/%d/coordinates',
                $this->entityId($draft),
                $this->entityId($point),
            );
        }

        self::assertInstanceOf(CityVisitPoint::class, $point);

        return sprintf(
            '/admin/studio/city-visits/%d/steps/%d/coordinates',
            $this->entityId($draft),
            $this->entityId($point),
        );
    }

    private function updateUrl(HikePoint|CityVisitPoint $point): string
    {
        if ($point instanceof HikePoint) {
            return sprintf('/admin/studio/hike-points/%d/update', $this->entityId($point));
        }

        return sprintf('/admin/studio/city-visit-points/%d/update', $this->entityId($point));
    }

    private function deleteUrl(HikePoint|CityVisitPoint $point): string
    {
        if ($point instanceof HikePoint) {
            return sprintf('/admin/studio/hike-points/%d/delete', $this->entityId($point));
        }

        return sprintf('/admin/studio/city-visit-points/%d/delete', $this->entityId($point));
    }
}
