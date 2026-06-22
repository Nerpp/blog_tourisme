<?php

namespace App\Tests\Functional;

use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use PHPUnit\Framework\Attributes\DataProvider;

final class HikeStudioControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromHikeEdit(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromHikeEdit(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenHikeEdit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testVerifiedAdminGetsNotFoundForMissingHikeDraft(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin/studio/hikes/2147483647/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHikePhotoUploadAccessIsProtected(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createVerifiedAdmin());

        $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()));
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()));

        self::assertResponseRedirects('/');
    }

    public function testHikePhotoUploadRejectsInvalidCsrfAndNonImageWithoutCreatingMedia(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $mediaRepository = $this->entityManager()->getRepository(MediaAsset::class);
        $before = $mediaRepository->count([]);

        $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()), [
            '_token' => 'invalid-token',
            'ajax' => '1',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(419);
        self::assertSame($before, $mediaRepository->count([]));

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $path = tempnam(sys_get_temp_dir(), 'hike-invalid-');
        self::assertIsString($path);
        file_put_contents($path, 'not an image');

        try {
            $client->request('POST', sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId()), [
                '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/hikes/%d/media/photos', $hike->getId())),
                'ajax' => '1',
            ], [
                'photos' => [new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'fake.jpg', 'image/jpeg', null, true)],
            ], ['HTTP_ACCEPT' => 'application/json']);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('error', (string) $client->getResponse()->getContent());
            self::assertSame($before, $mediaRepository->count([]));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testHikeMediaUpdatePromotesCoverAndDemotesPreviousCover(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $oldCover = $this->linkHikeMedia($hike, $this->createImageMedia('Ancienne cover randonnée'), MediaRole::Cover, 0);
        $newCover = $this->linkHikeMedia($hike, $this->createImageMedia('Nouvelle cover randonnée'), MediaRole::Gallery, 1);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hike-media/%d/update', $newCover->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/hike-media/%d/update', $newCover->getId())),
            'title' => 'Nouvelle cover randonnée',
            'altText' => 'Nouvelle image principale',
            'caption' => '',
            'imageType' => ImageType::Standard->value,
            'association' => 'main',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        $oldCover = $this->refresh($oldCover);
        $newCover = $this->refresh($newCover);
        self::assertSame(MediaRole::Gallery, $oldCover->getRole());
        self::assertSame(MediaRole::Cover, $newCover->getRole());
    }

    public function testHikeMediaDeletionRequiresCsrfAndKeepsOtherLink(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $deletedLink = $this->linkHikeMedia($hike, $this->createImageMedia('Photo randonnée supprimée'), MediaRole::Gallery, 0);
        $keptLink = $this->linkHikeMedia($hike, $this->createImageMedia('Photo randonnée conservée'), MediaRole::Gallery, 1);
        $deletedId = $deletedLink->getId();
        $keptId = $keptLink->getId();
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/studio/hike-media/%d/delete', $deletedId), ['_token' => 'invalid-token']);
        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertNotNull($this->entityManager()->find(HikeDraftMedia::class, $deletedId));

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hike-media/%d/delete', $deletedId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/hike-media/%d/delete', $deletedId)),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-photos', $hike->getId()));
        self::assertNull($this->entityManager()->find(HikeDraftMedia::class, $deletedId));
        self::assertNotNull($this->entityManager()->find(HikeDraftMedia::class, $keptId));
    }

    public function testHikeMediaCanMoveToOwnPointButIgnoresForeignPointAssociation(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $point = $this->createHikePoint($hike, 42.61, 2.91);
        $foreignHike = $this->createHikeDraft($admin);
        $foreignPoint = $this->createHikePoint($foreignHike, 43.61, 3.91);
        $movedMedia = $this->createImageMedia('Photo randonnée vers point');
        $foreignAttemptMedia = $this->createImageMedia('Photo randonnée reste generale');
        $movedLink = $this->linkHikeMedia($hike, $movedMedia, MediaRole::Gallery, 0);
        $foreignAttemptLink = $this->linkHikeMedia($hike, $foreignAttemptMedia, MediaRole::Cover, 1);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hike-media/%d/update', $movedLink->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/hike-media/%d/update', $movedLink->getId())),
            'title' => 'Photo rattachee au point',
            'altText' => 'Vue depuis le point',
            'caption' => 'Caption point',
            'imageType' => ImageType::Standard->value,
            'association' => 'point:'.$point->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertNull($this->entityManager()->find(HikeDraftMedia::class, $movedLink->getId()));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $movedMedia->getId()));
        self::assertInstanceOf(HikePointMedia::class, $this->entityManager()->getRepository(HikePointMedia::class)->findOneBy([
            'hikePoint' => $point,
            'mediaAsset' => $movedMedia,
        ]));
        self::assertNull($this->entityManager()->getRepository(HikePointMedia::class)->findOneBy([
            'hikePoint' => $foreignPoint,
            'mediaAsset' => $movedMedia,
        ]));

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hike-media/%d/update', $foreignAttemptLink->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/hike-media/%d/update', $foreignAttemptLink->getId())),
            'title' => 'Tentative point externe',
            'altText' => 'Alt point externe',
            'caption' => '',
            'imageType' => ImageType::Standard->value,
            'association' => 'point:'.$foreignPoint->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        $foreignAttemptLink = $this->refresh($foreignAttemptLink);
        self::assertSame(MediaRole::Gallery, $foreignAttemptLink->getRole());
        self::assertNotNull($this->entityManager()->find(HikeDraftMedia::class, $foreignAttemptLink->getId()));
        self::assertNull($this->entityManager()->getRepository(HikePointMedia::class)->findOneBy([
            'hikePoint' => $foreignPoint,
            'mediaAsset' => $foreignAttemptMedia,
        ]));
    }

    public function testDeletingHikePointMediaKeepsMediaAssetAndOtherPointLinks(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $deletedPoint = $this->createHikePoint($hike, 42.50, 2.70, 1);
        $keptPoint = $this->createHikePoint($hike, 42.60, 2.80, 2);
        $deletedMedia = $this->createImageMedia('Photo point randonnée retiree');
        $keptMedia = $this->createImageMedia('Photo autre point randonnée');
        $deletedLink = (new HikePointMedia())->setHikePoint($deletedPoint)->setMediaAsset($deletedMedia);
        $keptLink = (new HikePointMedia())->setHikePoint($keptPoint)->setMediaAsset($keptMedia);
        $deletedPoint->addMediaLink($deletedLink);
        $keptPoint->addMediaLink($keptLink);
        $this->persistAndFlush($deletedLink, $keptLink);
        $deletedLinkId = $deletedLink->getId();
        $keptLinkId = $keptLink->getId();
        $deletedMediaId = $deletedMedia->getId();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hike-point-media/%d/delete', $deletedLinkId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/hike-point-media/%d/delete', $deletedLinkId)),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#point-%d', $hike->getId(), $deletedPoint->getId()));
        self::assertNull($this->entityManager()->find(HikePointMedia::class, $deletedLinkId));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $deletedMediaId));
        self::assertNotNull($this->entityManager()->find(HikePointMedia::class, $keptLinkId));
        self::assertInstanceOf(HikePointMedia::class, $this->entityManager()->getRepository(HikePointMedia::class)->findOneBy([
            'hikePoint' => $keptPoint,
            'mediaAsset' => $keptMedia,
        ]));
    }

    public function testMissingHikeMediaReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/studio/hike-media/2147483647/delete', [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testStudioHeaderSeparatesEditorialDestinationAndMissingGeographicLocation(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $editorialDestination = $this->createDestination('Llo studio editorial');
        $hike = $this->createHikeDraft($admin, $editorialDestination);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Destination éditoriale');
        self::assertSelectorTextContains('body', 'Llo studio editorial');
        self::assertSelectorTextContains('body', 'Localisation géographique');
        self::assertSelectorTextContains('body', 'Localisation géographique non associée');
        self::assertStringNotContainsString('Destination non associée', $client->getResponse()->getContent() ?: '');
    }

    public function testVerifiedAdminCanEditHikeWithGeographicCommuneOnly(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = $this->createHikeDraft($admin);
        $title = 'Randonnée fonctionnelle modifiée '.$this->uniqueToken('hike-edit');
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $title,
            'destination' => '',
            'status' => HikeDraftStatus::Finished->value,
            'detectedCommuneName' => 'Collioure',
            'detectedCommuneCode' => '66053',
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'notes' => 'Notes fonctionnelles.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        self::assertSame($title, $hike->getTitle());
        self::assertSame(HikeDraftStatus::Finished, $hike->getStatus());
        self::assertInstanceOf(Destination::class, $hike->getGeographicDestination());
        self::assertSame('66053', $hike->getGeographicDestination()->getCode());
        self::assertSame($hike->getGeographicDestination()->getId(), $hike->getDestination()?->getId());
        self::assertNotNull($hike->getFinishedAt());
    }

    public function testDraftWithoutLocationCanBeSaved(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Brouillon randonnée incomplet',
            'destination' => '',
            'status' => HikeDraftStatus::Draft->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'notes' => 'Notes conservées sans localisation.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        self::assertSame('Brouillon randonnée incomplet', $hike->getTitle());
        self::assertSame(HikeDraftStatus::Draft, $hike->getStatus());
        self::assertSame('Notes conservées sans localisation.', $hike->getNotes());
        self::assertNull($hike->getDestination());
        self::assertNull($hike->getGeographicDestination());
        self::assertNull($hike->getFinishedAt());
    }

    public function testHikePublicationWithoutValidatedLocationIsRefusedButOtherDraftChangesAreSaved(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Randonnée prête sauf localisation',
            'destination' => '',
            'status' => HikeDraftStatus::Finished->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'notes' => 'Contenu éditorial sauvegardé.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Sélectionnez une commune valide dans les propositions avant de publier.',
            (string) $client->getResponse()->getContent(),
        );

        $hike = $this->refresh($hike);
        self::assertSame('Randonnée prête sauf localisation', $hike->getTitle());
        self::assertSame('Contenu éditorial sauvegardé.', $hike->getNotes());
        self::assertSame(HikeDraftStatus::Draft, $hike->getStatus());
        self::assertNull($hike->getDestination());
        self::assertNull($hike->getGeographicDestination());
        self::assertNull($hike->getFinishedAt());
    }

    /**
     * @return iterable<string, array{
     *     communeName: string,
     *     communeCode: string,
     *     requestedStatus: HikeDraftStatus,
     *     expectedMessage: string
     * }>
     */
    public static function partialHikeCommuneProvider(): iterable
    {
        yield 'name without INSEE code' => [
            'communeName' => 'Commune partielle',
            'communeCode' => '',
            'requestedStatus' => HikeDraftStatus::Draft,
            'expectedMessage' => 'Sélectionnez une commune complète dans les propositions avant d’enregistrer la localisation.',
        ];

        yield 'INSEE code without name during publication' => [
            'communeName' => '',
            'communeCode' => '99301',
            'requestedStatus' => HikeDraftStatus::Finished,
            'expectedMessage' => 'Sélectionnez une commune valide dans les propositions avant de publier.',
        ];
    }

    #[DataProvider('partialHikeCommuneProvider')]
    public function testPartialHikeCommuneWarnsAndPreservesExistingLocation(
        string $communeName,
        string $communeCode,
        HikeDraftStatus $requestedStatus,
        string $expectedMessage,
    ): void {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $existingCode = strtoupper(substr($this->uniqueToken('hike-existing'), 0, 18));
        $geographicDestination = $this->createDestination(
            'Commune randonnée existante',
            DestinationType::City,
            code: $existingCode,
        );
        $hike = $this->createHikeDraft($admin, $geographicDestination);
        $hike
            ->setGeographicDestination($geographicDestination)
            ->setDetectedCommuneName('Commune randonnée existante')
            ->setDetectedCommuneCode($existingCode);
        $point = $this->createHikePoint($hike, 42.4455, 2.6677);
        $this->persistAndFlush($hike);
        $destinationCount = $this->entityManager()->getRepository(Destination::class)->count([]);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Brouillon randonnée modifié avec commune partielle',
            'destination' => '',
            'status' => $requestedStatus->value,
            'detectedCommuneName' => $communeName,
            'detectedCommuneCode' => $communeCode,
            'locationLatitude' => '48.0000',
            'locationLongitude' => '3.0000',
            'notes' => 'Notes non géographiques conservées.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $expectedMessage,
            (string) $client->getResponse()->getContent(),
        );

        $hike = $this->refresh($hike);
        $point = $this->refresh($point);
        self::assertSame('Brouillon randonnée modifié avec commune partielle', $hike->getTitle());
        self::assertSame('Notes non géographiques conservées.', $hike->getNotes());
        self::assertSame(HikeDraftStatus::Draft, $hike->getStatus());
        self::assertSame($geographicDestination->getId(), $hike->getDestination()?->getId());
        self::assertSame($geographicDestination->getId(), $hike->getGeographicDestination()?->getId());
        self::assertSame('Commune randonnée existante', $hike->getDetectedCommuneName());
        self::assertSame($existingCode, $hike->getDetectedCommuneCode());
        self::assertSame(42.4455, $point->getLatitude());
        self::assertSame(2.6677, $point->getLongitude());
        self::assertSame($destinationCount, $this->entityManager()->getRepository(Destination::class)->count([]));
    }

    public function testVerifiedAdminCanUpdateHikePointCoordinatesAndAccuracy(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = $this->createHikeDraft($admin);
        $point = $this->createHikePoint($hike);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter(sprintf('#point-%d [data-high-precision-gps] [data-gps-start]', $point->getId()))->count());

        $client->request('POST', sprintf('/admin/studio/hike-points/%d/update', $point->getId()), [
            '_token' => $this->inputValue($crawler, sprintf('#point-%d input[name="_token"]', $point->getId())),
            '_redirect_anchor' => 'point-'.$point->getId(),
            'title' => 'Belvedere precis',
            'type' => HikePointType::Viewpoint->value,
            'position' => '3',
            'latitude' => '42.6986123',
            'longitude' => '2.8956456',
            'accuracy' => '4',
            'note' => 'Position relevee depuis le telephone.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#point-%d', $hike->getId(), $point->getId()));
        $point = $this->refresh($point);
        self::assertSame('Belvedere precis', $point->getTitle());
        self::assertSame(HikePointType::Viewpoint, $point->getType());
        self::assertSame(3, $point->getPosition());
        self::assertSame(42.6986123, $point->getLatitude());
        self::assertSame(2.8956456, $point->getLongitude());
        self::assertSame(4.0, $point->getAccuracy());
        self::assertSame('Position relevee depuis le telephone.', $point->getNote());
    }

    public function testHikePointUpdateWithInvalidCsrfDoesNotMutatePoint(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $point = $this->createHikePoint($hike, 42.45, 2.65, 2);
        $point
            ->setType(HikePointType::Rest)
            ->setTitle('Pause originale')
            ->setNote('Note originale')
            ->setAccuracy(5.0);
        $this->persistAndFlush($point);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/studio/hike-points/%d/update', $point->getId()), [
            '_token' => 'invalid-token',
            'title' => 'Pause modifiée',
            'type' => HikePointType::Danger->value,
            'position' => '5',
            'latitude' => '43.0000',
            'longitude' => '3.0000',
            'accuracy' => '12',
            'note' => 'Note modifiée',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#point-%d', $hike->getId(), $point->getId()));
        $point = $this->refresh($point);
        self::assertSame('Pause originale', $point->getTitle());
        self::assertSame(HikePointType::Rest, $point->getType());
        self::assertSame(2, $point->getPosition());
        self::assertSame(42.45, $point->getLatitude());
        self::assertSame(2.65, $point->getLongitude());
        self::assertSame(5.0, $point->getAccuracy());
        self::assertSame('Note originale', $point->getNote());
    }

    public function testHikePointUpdateWithInvalidPositionDoesNotPersistPartialMutation(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $point = $this->createHikePoint($hike, 42.51, 2.71, 4);
        $point
            ->setType(HikePointType::Water)
            ->setTitle('Source originale')
            ->setNote('Eau potable')
            ->setAccuracy(9.0);
        $this->persistAndFlush($point);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hike-points/%d/update', $point->getId()), [
            '_token' => $this->inputValue($crawler, sprintf('#point-%d input[name="_token"]', $point->getId())),
            'title' => 'Source déplacée',
            'type' => HikePointType::Start->value,
            'position' => '0',
            'latitude' => '43.1000',
            'longitude' => '3.1000',
            'accuracy' => '2',
            'note' => 'Mutation partielle interdite',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#point-%d', $hike->getId(), $point->getId()));
        $client->followRedirect();
        self::assertStringContainsString('La position du point doit être supérieure ou égale à 1.', (string) $client->getResponse()->getContent());
        $point = $this->refresh($point);
        self::assertSame('Source originale', $point->getTitle());
        self::assertSame(HikePointType::Water, $point->getType());
        self::assertSame(4, $point->getPosition());
        self::assertSame(42.51, $point->getLatitude());
        self::assertSame(2.71, $point->getLongitude());
        self::assertSame(9.0, $point->getAccuracy());
        self::assertSame('Eau potable', $point->getNote());
    }

    public function testStudioHikeLocationPickerCanUpdatePrimaryPointWithoutEditorialDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = $this->createHikeDraft($admin);
        $point = $this->createHikePoint($hike, 42.1, 2.1);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $hike->getTitle(),
            'destination' => '',
            'status' => HikeDraftStatus::Draft->value,
            'detectedCommuneName' => 'Collioure',
            'detectedCommuneCode' => '66053',
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'locationCountry' => 'France',
            'locationDepartmentCode' => '66',
            'communeCenterLatitude' => '42.5250500',
            'communeCenterLongitude' => '3.0831600',
            'locationLatitude' => '42.5260000',
            'locationLongitude' => '3.0840000',
            'locationAccuracy' => '8',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        $point = $this->refresh($point);
        self::assertInstanceOf(Destination::class, $hike->getGeographicDestination());
        self::assertSame('66053', $hike->getGeographicDestination()->getCode());
        self::assertSame($hike->getGeographicDestination()->getId(), $hike->getDestination()?->getId());
        self::assertSame(42.52505, $hike->getGeographicDestination()->getLatitude());
        self::assertSame(42.526, $point->getLatitude());
        self::assertSame(3.084, $point->getLongitude());
        self::assertSame(8.0, $point->getAccuracy());
    }

    public function testStudioHikeLocationPickerDoesNotLoseExistingPointWithoutCoordinates(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = $this->createHikeDraft($admin);
        $point = $this->createHikePoint($hike, 42.44, 2.44);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $hike->getTitle(),
            'destination' => '',
            'status' => HikeDraftStatus::Draft->value,
            'detectedCommuneName' => 'Ceret',
            'detectedCommuneCode' => '66049',
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $point = $this->refresh($point);
        self::assertSame(42.44, $point->getLatitude());
        self::assertSame(2.44, $point->getLongitude());
    }

    public function testStudioHikeSaveWithoutSelectedCommuneDoesNotReplaceGeographicLocationOrPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $country = $this->createDestination('France keep geo', DestinationType::Country, null, 'FR');
        $region = $this->createDestination('Occitanie keep geo', DestinationType::Region, $country, '76');
        $department = $this->createDestination('Pyrenees-Orientales keep geo', DestinationType::Department, $region, '66');
        $geographicDestination = $this->createDestination('Ceret keep geo', DestinationType::City, $department, '66049');
        $editorialDestination = $this->createDestination('Llo keep editorial', DestinationType::City, $department, '66100');
        $hike = $this->createHikeDraft($admin, $editorialDestination);
        $hike
            ->setGeographicDestination($geographicDestination)
            ->setDetectedCommuneName('Ceret keep geo')
            ->setDetectedCommuneCode('66049')
            ->setDetectedDepartmentName('Pyrenees-Orientales keep geo')
            ->setDetectedRegionName('Occitanie keep geo');
        $point = $this->createHikePoint($hike, 42.44, 2.44);
        $this->persistAndFlush($hike);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $hike->getTitle(),
            'destination' => (string) $editorialDestination->getId(),
            'status' => HikeDraftStatus::Draft->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'detectedDepartmentName' => '',
            'detectedRegionName' => '',
            'locationLatitude' => '43.0000000',
            'locationLongitude' => '3.0000000',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        $point = $this->refresh($point);
        self::assertSame($geographicDestination->getId(), $hike->getGeographicDestination()?->getId());
        self::assertSame('Ceret keep geo', $hike->getDetectedCommuneName());
        self::assertSame(42.44, $point->getLatitude());
        self::assertSame(2.44, $point->getLongitude());
        self::assertSame($editorialDestination->getId(), $hike->getDestination()?->getId());
    }

    public function testPublishedHikeCanReturnToDraftWithoutLosingItsGeographicLocationOrPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $communeCode = strtoupper(substr($this->uniqueToken('hike-draft'), 0, 18));
        $geographicDestination = $this->createDestination('Commune hike retour brouillon', DestinationType::City, code: $communeCode);
        $hike = $this->createPublishedHike($admin, $geographicDestination);
        $hike
            ->setGeographicDestination($geographicDestination)
            ->setDetectedCommuneName('Commune hike retour brouillon')
            ->setDetectedCommuneCode($communeCode);
        $point = $this->createHikePoint($hike, 42.1234, 2.5678);
        $finishedAt = $hike->getFinishedAt();
        $this->persistAndFlush($hike);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $hike->getTitle(),
            'destination' => (string) $geographicDestination->getId(),
            'status' => HikeDraftStatus::Draft->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'notes' => 'Retour en préparation.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        $point = $this->refresh($point);
        self::assertSame(HikeDraftStatus::Draft, $hike->getStatus());
        self::assertSame($geographicDestination->getId(), $hike->getDestination()?->getId());
        self::assertSame($geographicDestination->getId(), $hike->getGeographicDestination()?->getId());
        self::assertSame('Commune hike retour brouillon', $hike->getDetectedCommuneName());
        self::assertSame(42.1234, $point->getLatitude());
        self::assertSame(2.5678, $point->getLongitude());
        self::assertSame($finishedAt?->getTimestamp(), $hike->getFinishedAt()?->getTimestamp());
    }

    public function testIncompleteHikeGpsRefusesPublicationWithoutPersistingPartialChanges(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $editorialDestination = $this->createDestination('Destination hike avant erreur');
        $hike = $this->createHikeDraft($admin, $editorialDestination);
        $originalTitle = (string) $hike->getTitle();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Titre hike qui ne doit pas être persisté',
            'destination' => (string) $editorialDestination->getId(),
            'status' => HikeDraftStatus::Finished->value,
            'detectedCommuneName' => 'Commune hike incomplète',
            'detectedCommuneCode' => strtoupper(substr($this->uniqueToken('hike-error'), 0, 18)),
            'detectedDepartmentName' => 'Département test',
            'detectedRegionName' => 'Région test',
            'locationLatitude' => '42.1234',
            'locationLongitude' => '',
            'notes' => 'Notes qui ne doivent pas être persistées.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Le point GPS doit contenir une latitude et une longitude valides.',
            (string) $client->getResponse()->getContent(),
        );

        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(HikeDraft::class, $hike->getId());
        self::assertInstanceOf(HikeDraft::class, $stored);
        self::assertSame($originalTitle, $stored->getTitle());
        self::assertSame(HikeDraftStatus::Draft, $stored->getStatus());
        self::assertSame($editorialDestination->getId(), $stored->getDestination()?->getId());
        self::assertNull($stored->getGeographicDestination());
        self::assertNull($stored->getNotes());
        self::assertNull($stored->getFinishedAt());
    }

    public function testStudioManualLocationValidationUpdatesEditorialAndGeographicDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $country = $this->createDestination('France studio geo', DestinationType::Country, null, 'FR');
        $region = $this->createDestination('Occitanie studio geo', DestinationType::Region, $country, '76');
        $department = $this->createDestination('Pyrenees-Orientales studio geo', DestinationType::Department, $region, '66');
        $suggestedCommuneCode = strtoupper(substr($this->uniqueToken('calce'), 0, 18));
        $manualCommuneCode = strtoupper(substr($this->uniqueToken('prades'), 0, 18));
        $manualCommune = $this->createDestination('Prades studio manuel', DestinationType::City, $department, $manualCommuneCode);
        $editorialDestination = $this->createDestination('Llo studio destination', DestinationType::City, $department, '66100');
        $hike = $this->createHikeDraft($admin, $editorialDestination);
        $point = $this->createHikePoint($hike, 42.7584, 2.7538);
        $point
            ->setDetectedCommuneName('Calce studio geo')
            ->setDetectedCommuneCode($suggestedCommuneCode)
            ->setDetectedDepartmentName('Pyrenees-Orientales studio geo')
            ->setDetectedRegionName('Occitanie studio geo')
            ->setAccuracy(1635.0);
        $this->persistAndFlush($point);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune localisation géographique enregistrée');
        self::assertSelectorTextContains('body', 'Point GPS actuellement enregistré');
        self::assertSelectorTextContains('body', 'Valider la commune et le point GPS');
        self::assertSelectorTextContains('body', 'Vérifier dans Google Maps');
        self::assertSame('', $this->inputValue($crawler, '#hike-commune'));
        self::assertSame('', $this->inputValue($crawler, '#hike-commune-code'));
        self::assertSame('', $this->inputValue($crawler, '#hike-location-latitude'));
        self::assertSame('', $this->inputValue($crawler, '#hike-location-longitude'));
        self::assertGreaterThan(0, $crawler->filter('[data-validate-point][disabled]')->count());
        self::assertStringNotContainsString('Suggestion détectée depuis les points GPS', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('Utiliser Calce studio geo comme localisation géographique', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('Ouvrir dans OpenStreetMap', $client->getResponse()->getContent() ?: '');

        $hikeTitle = $hike->getTitle();

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $hikeTitle,
            'destination' => (string) $editorialDestination->getId(),
            'status' => HikeDraftStatus::Finished->value,
            'detectedCommuneName' => 'Prades studio manuel',
            'detectedCommuneCode' => $manualCommuneCode,
            'detectedDepartmentName' => 'Pyrenees-Orientales studio geo',
            'detectedRegionName' => 'Occitanie studio geo',
            'locationCountry' => 'France',
            'locationDepartmentCode' => '66',
            'communeCenterLatitude' => '42.6170000',
            'communeCenterLongitude' => '2.4210000',
            'locationLatitude' => '42.6195000',
            'locationLongitude' => '2.4245000',
            'locationAccuracy' => '',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        $point = $this->refresh($point);
        self::assertSame($manualCommune->getId(), $hike->getGeographicDestination()?->getId());
        self::assertSame($manualCommune->getId(), $hike->getDestination()?->getId());
        self::assertNotSame($editorialDestination->getId(), $hike->getDestination()?->getId());
        self::assertSame('Prades studio manuel', $hike->getDetectedCommuneName());
        self::assertSame($manualCommuneCode, $hike->getDetectedCommuneCode());
        self::assertSame('Pyrenees-Orientales studio geo', $hike->getDetectedDepartmentName());
        self::assertSame('Occitanie studio geo', $hike->getDetectedRegionName());
        self::assertNotSame(42.7584, $point->getLatitude());
        self::assertNotSame(2.7538, $point->getLongitude());
        self::assertSame(42.6195, $point->getLatitude());
        self::assertSame(2.4245, $point->getLongitude());
        self::assertNull($point->getAccuracy());
        self::assertSame('Prades studio manuel', $point->getDetectedCommuneName());
        self::assertSame($manualCommuneCode, $point->getDetectedCommuneCode());

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Destination éditoriale');
        self::assertSelectorTextContains('body', 'Localisation géographique');
        self::assertSelectorTextContains('body', 'Prades studio manuel');
        self::assertSelectorTextContains('body', 'Point GPS actuellement enregistré');
        self::assertSelectorTextContains('body', '42.6195000');
        self::assertSelectorTextContains('body', '2.4245000');

        $client->request('GET', sprintf('/destinations/%s', $manualCommune->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $hikeTitle);

        $client->request('GET', sprintf('/destinations/%s', $editorialDestination->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($hikeTitle, $client->getResponse()->getContent() ?: '');
    }

    public function testStudioManualLocationValidationReplacesExistingCommuneAndPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $country = $this->createDestination('France studio replace', DestinationType::Country, null, 'FR');
        $region = $this->createDestination('Occitanie studio replace', DestinationType::Region, $country, '76');
        $herault = $this->createDestination('Herault studio replace', DestinationType::Department, $region, '34');
        $aude = $this->createDestination('Aude studio replace', DestinationType::Department, $region, '11');
        $beziersCode = '34'.(string) random_int(100, 999);
        $narbonneCode = '11'.(string) random_int(100, 999);
        $beziers = $this->createDestination('Beziers studio replace', DestinationType::City, $herault, $beziersCode);
        $narbonne = $this->createDestination('Narbonne studio replace', DestinationType::City, $aude, $narbonneCode);
        $hike = $this->createHikeDraft($admin, $beziers);
        $hike
            ->setGeographicDestination($beziers)
            ->setDetectedCommuneName('Beziers studio replace')
            ->setDetectedCommuneCode($beziersCode)
            ->setDetectedDepartmentName('Herault studio replace')
            ->setDetectedRegionName('Occitanie studio replace');
        $point = $this->createHikePoint($hike, 43.3442, 3.2158);
        $point
            ->setDetectedCommuneName('Beziers studio replace')
            ->setDetectedCommuneCode($beziersCode)
            ->setDetectedDepartmentName('Herault studio replace')
            ->setDetectedRegionName('Occitanie studio replace')
            ->setAccuracy(7.0);
        $this->persistAndFlush($hike, $point);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Destination éditoriale');
        self::assertSelectorTextContains('body', 'Beziers studio replace');
        self::assertSelectorTextContains('body', 'Localisation géographique');
        self::assertSame('Beziers studio replace', $this->inputValue($crawler, '#hike-commune'));
        self::assertSame($beziersCode, $this->inputValue($crawler, '#hike-commune-code'));
        self::assertSame('43.3442', $this->inputValue($crawler, '#hike-location-latitude'));
        self::assertSame('3.2158', $this->inputValue($crawler, '#hike-location-longitude'));

        $client->request('POST', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $hike->getTitle(),
            'destination' => (string) $beziers->getId(),
            'status' => HikeDraftStatus::Draft->value,
            'detectedCommuneName' => 'Narbonne studio replace',
            'detectedCommuneCode' => $narbonneCode,
            'detectedDepartmentName' => 'Aude studio replace',
            'detectedRegionName' => 'Occitanie studio replace',
            'locationCountry' => 'France',
            'locationDepartmentCode' => '11',
            'communeCenterLatitude' => '43.1843000',
            'communeCenterLongitude' => '3.0031000',
            'locationLatitude' => '43.1838000',
            'locationLongitude' => '3.0042000',
            'locationAccuracy' => '12',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-publication', $hike->getId()));
        $hike = $this->refresh($hike);
        $point = $this->refresh($point);
        self::assertSame($narbonne->getId(), $hike->getDestination()?->getId());
        self::assertSame($narbonne->getId(), $hike->getGeographicDestination()?->getId());
        self::assertNotSame($beziers->getId(), $hike->getDestination()?->getId());
        self::assertSame('Narbonne studio replace', $hike->getDetectedCommuneName());
        self::assertSame($narbonneCode, $hike->getDetectedCommuneCode());
        self::assertSame('Aude studio replace', $hike->getDetectedDepartmentName());
        self::assertSame('Occitanie studio replace', $hike->getDetectedRegionName());
        self::assertSame(43.1838, $point->getLatitude());
        self::assertSame(3.0042, $point->getLongitude());
        self::assertSame(12.0, $point->getAccuracy());
        self::assertSame('Narbonne studio replace', $point->getDetectedCommuneName());
        self::assertSame($narbonneCode, $point->getDetectedCommuneCode());

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'Destination éditoriale');
        self::assertSelectorTextContains('body', 'Narbonne studio replace');
        self::assertSelectorTextContains('body', 'Localisation géographique');
        self::assertSelectorTextContains('body', '43.1838000');
        self::assertSelectorTextContains('body', '3.0042000');
        self::assertSame('Narbonne studio replace', $this->inputValue($crawler, '#hike-commune'));
        self::assertSame($narbonneCode, $this->inputValue($crawler, '#hike-commune-code'));
        self::assertSame('43.1838', $this->inputValue($crawler, '#hike-location-latitude'));
        self::assertSame('3.0042', $this->inputValue($crawler, '#hike-location-longitude'));
    }
}
