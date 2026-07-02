<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPointMedia;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Tests\Support\TestImageFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CityVisitStudioControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromCityVisitEdit(): void
    {
        $client = static::createClient();
        $cityVisit = $this->createCityVisitDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromCityVisitEdit(): void
    {
        $client = static::createClient();
        $cityVisit = $this->createCityVisitDraft($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenCityVisitEdit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testVerifiedAdminGetsNotFoundForMissingCityVisitDraft(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin/studio/city-visits/2147483647/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCityVisitVideoActionIsProtectedAndRejectsMissingUrl(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()));
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()));
        self::assertResponseRedirects('/');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $mediaRepository = $this->entityManager()->getRepository(MediaAsset::class);
        $before = $mediaRepository->count([]);

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId())),
            'externalUrl' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertSame($before, $mediaRepository->count([]));
    }

    public function testCityVisitBulkPhotoUploadReturnsJsonForExpiredForm(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);

        $client->request(
            'POST',
            sprintf('/admin/studio/city-visits/%d/media/photos/bulk-upload', $cityVisit->getId()),
            ['_token' => 'bad-token'],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(419);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame(1, $payload['failed']);
        self::assertSame(1, $payload['total']);
        self::assertSame(0, $this->entityManager()->getRepository(CityVisitDraftMedia::class)->count(['cityVisitDraft' => $cityVisit]));
    }

    public function testCityVisitBulkPhotoUploadRejectsOversizedJsonAndHtmlSelections(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/city-visits/%d/media/photos/bulk-upload', $cityVisit->getId());
        $token = $this->csrfTokenForClient($client, 'studio_city_visit_photos_'.$cityVisit->getId());
        $paths = [];

        try {
            for ($index = 0; $index <= 50; ++$index) {
                $path = sprintf('%s/city-bulk-limit-%s-%02d.jpg', sys_get_temp_dir(), bin2hex(random_bytes(4)), $index);
                file_put_contents($path, 'selection limit fixture');
                $paths[] = $path;
            }
            $uploads = static fn (): array => array_map(
                static fn (string $path): UploadedFile => new UploadedFile($path, basename($path), 'image/jpeg', null, true),
                $paths,
            );

            $client->request('POST', $url, ['_token' => $token], ['photos' => $uploads()], ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(413);
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringContainsString('Sélection limitée', (string) $payload['error']);

            $client->request('POST', $url, ['_token' => $token], ['photos' => $uploads()]);
            self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        self::assertSame(0, $this->entityManager()->getRepository(CityVisitDraftMedia::class)->count(['cityVisitDraft' => $cityVisit]));
    }

    public function testCityVisitVideoRejectsForeignPointBeforeCreatingMedia(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $foreignCityVisit = $this->createCityVisitDraft($admin);
        $foreignPoint = $this->createCityVisitPoint($foreignCityVisit);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $mediaCount = $this->entityManager()->getRepository(MediaAsset::class)->count([]);

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId())),
            'title' => 'Vidéo visite externe refusée',
            'externalUrl' => 'https://example.test/foreign-city-video',
            'association' => 'point:'.$foreignPoint->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));
    }

    public function testCityVisitVideoCanBeAddedToGalleryAndPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $point = $this->createCityVisitPoint($cityVisit, 43.55, 3.75);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId())),
            'title' => 'Vidéo galerie visite',
            'caption' => 'Vue générale visite',
            'videoType' => VideoType::Youtube->value,
            'externalUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'association' => 'gallery',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $galleryVideo = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(['title' => 'Vidéo galerie visite']);
        self::assertInstanceOf(MediaAsset::class, $galleryVideo);
        self::assertSame(MediaType::Video, $galleryVideo->getMediaType());
        self::assertSame(VideoType::Youtube, $galleryVideo->getVideoType());
        self::assertSame('https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $galleryVideo->getThumbnailPath());
        $galleryLink = $this->entityManager()->getRepository(CityVisitDraftMedia::class)->findOneBy([
            'cityVisitDraft' => $cityVisit,
            'mediaAsset' => $galleryVideo,
        ]);
        self::assertInstanceOf(CityVisitDraftMedia::class, $galleryLink);
        self::assertSame(MediaRole::Gallery, $galleryLink->getRole());

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId())),
            'title' => 'Vidéo point visite',
            'caption' => 'Vidéo rattachée au point visite',
            'videoType' => VideoType::External->value,
            'externalUrl' => 'https://example.test/video-point-visite',
            'association' => 'point:'.$point->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $pointVideo = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(['title' => 'Vidéo point visite']);
        self::assertInstanceOf(MediaAsset::class, $pointVideo);
        self::assertSame(VideoType::External, $pointVideo->getVideoType());
        self::assertInstanceOf(CityVisitPointMedia::class, $this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $point,
            'mediaAsset' => $pointVideo,
        ]));
        self::assertNull($this->entityManager()->getRepository(CityVisitDraftMedia::class)->findOneBy([
            'cityVisitDraft' => $cityVisit,
            'mediaAsset' => $pointVideo,
        ]));
    }

    public function testCityVisitStudioMutationFormsRejectExpiredTokens(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $media = $this->createImageMedia('Média visite inchangé');
        $link = $this->linkCityVisitMedia($cityVisit, $media, MediaRole::Gallery, 0);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => 'bad-token',
            'title' => 'Titre visite refusé',
        ]);
        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/video', $cityVisit->getId()), [
            '_token' => 'bad-token',
            'externalUrl' => 'https://example.test/refused-city-video',
        ]);
        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));

        $client->request('POST', sprintf('/admin/studio/city-visit-media/%d/update', $link->getId()), [
            '_token' => 'bad-token',
            'title' => 'Média visite refusé',
        ]);
        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertSame('Média visite inchangé', $this->refresh($media)->getTitle());
    }

    public function testCityVisitVideoMediaUpdateRefreshesMetadataAndThumbnail(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $video = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setTitle('Ancienne vidéo visite')
            ->setExternalUrl('https://example.test/old-city-video');
        $this->persistAndFlush($video);
        $link = $this->linkCityVisitMedia($cityVisit, $video, MediaRole::Gallery, 0);
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/city-visit-media/%d/update', $link->getId());
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', $url, [
            '_token' => $this->tokenFromFormAction($crawler, $url),
            'title' => 'Nouvelle vidéo visite',
            'caption' => 'Légende vidéo visite actualisée',
            'videoType' => VideoType::Youtube->value,
            'externalUrl' => 'https://www.youtube.com/watch?v=9bZkp7q19f0',
            'association' => 'gallery',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $video = $this->refresh($video);
        self::assertSame('Nouvelle vidéo visite', $video->getTitle());
        self::assertSame('Légende vidéo visite actualisée', $video->getCaption());
        self::assertSame(VideoType::Youtube, $video->getVideoType());
        self::assertSame('https://www.youtube.com/watch?v=9bZkp7q19f0', $video->getExternalUrl());
        self::assertSame('https://img.youtube.com/vi/9bZkp7q19f0/hqdefault.jpg', $video->getThumbnailPath());
        self::assertSame(MediaRole::Gallery, $this->refresh($link)->getRole());
    }

    public function testCityVisitDeleteRequiresCsrfAndRemovesDraft(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $cityVisitId = $cityVisit->getId();
        self::assertNotNull($cityVisitId);
        $this->linkCityVisitMedia($cityVisit, $this->createImageMedia('Photo visite à nettoyer'), MediaRole::Gallery, 0);
        $point = $this->createCityVisitPoint($cityVisit);
        $pointMedia = $this->createImageMedia('Photo de point visite à nettoyer');
        $pointMediaId = $pointMedia->getId();
        $pointLink = (new CityVisitPointMedia())->setCityVisitPoint($point)->setMediaAsset($pointMedia);
        $point->addMediaLink($pointLink);
        $this->persistAndFlush($pointLink);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/field-tools/city-visits');
        self::assertResponseIsSuccessful();
        $token = $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/delete', $cityVisitId));

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/delete', $cityVisitId), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects();
        self::assertInstanceOf(CityVisitDraft::class, $this->entityManager()->find(CityVisitDraft::class, $cityVisitId));

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/delete', $cityVisitId), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        self::assertArrayHasKey('success', $client->getRequest()->getSession()->getFlashBag()->peekAll());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(CityVisitDraft::class, $cityVisitId));
        self::assertNull($this->entityManager()->find(MediaAsset::class, $pointMediaId));
    }

    public function testCityVisitPhotoUploadCreatesCoverThroughRealForm(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 96, 48);
        $createdMedia = null;

        try {
            $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/photos', $cityVisit->getId()), [
                '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/media/photos', $cityVisit->getId())),
                'photoCaptions' => ['Photo principale fonctionnelle'],
                'photoImageTypes' => [ImageType::Standard->value],
                'photoAssociations' => ['main'],
                'ajax' => '1',
            ], [
                'photos' => [new UploadedFile($source, 'city-cover.jpg', 'image/jpeg', null, true)],
            ], ['HTTP_ACCEPT' => 'application/json']);

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('"success":true', (string) $client->getResponse()->getContent());
            $createdMedia = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(
                ['uploadedBy' => $admin],
                ['id' => 'DESC'],
            );
            self::assertInstanceOf(MediaAsset::class, $createdMedia);
            $link = $this->entityManager()->getRepository(CityVisitDraftMedia::class)->findOneBy([
                'cityVisitDraft' => $cityVisit,
                'mediaAsset' => $createdMedia,
            ]);
            self::assertInstanceOf(CityVisitDraftMedia::class, $link);
            self::assertSame(MediaRole::Cover, $link->getRole());
            self::assertSame('Photo principale fonctionnelle', $createdMedia->getCaption());
        } finally {
            if ($createdMedia instanceof MediaAsset) {
                $this->removeGeneratedMediaFiles($createdMedia);
            }
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testCityVisitPhotoHtmlFlowRejectsExpiredAndForeignPointAssociations(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $foreignPoint = $this->createCityVisitPoint($this->createCityVisitDraft($admin));
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/city-visits/%d/media/photos', $cityVisit->getId());

        $client->request('POST', $url, ['_token' => 'bad-token']);
        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 96, 48);
        $mediaCount = $this->entityManager()->getRepository(MediaAsset::class)->count([]);

        try {
            $client->request('POST', $url, [
                '_token' => $this->tokenFromFormAction($crawler, $url),
                'photoAssociations' => ['point:'.$foreignPoint->getId()],
            ], [
                'photos' => [new UploadedFile($source, 'foreign-city-point-html.jpg', 'image/jpeg', null, true)],
            ]);

            self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
            self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));

            $client->request('POST', $url, [
                '_token' => $this->csrfTokenForClient($client, 'studio_city_visit_photos_'.$cityVisit->getId()),
                'photoAssociations' => [['point:'.$foreignPoint->getId()]],
            ], [
                'photos' => [new UploadedFile($source, 'structured-city-association.jpg', 'image/jpeg', null, true)],
            ]);
            self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
            self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testCityVisitPhotoUploadCreatesPointMediaThroughHtmlFlow(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $point = $this->createCityVisitPoint($cityVisit);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $url = sprintf('/admin/studio/city-visits/%d/media/photos', $cityVisit->getId());
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 96, 48);
        $createdMedia = null;

        try {
            $client->request('POST', $url, [
                '_token' => $this->tokenFromFormAction($crawler, $url),
                'photoCaptions' => ['Photo de visite rattachée au point'],
                'photoImageTypes' => [ImageType::Standard->value],
                'photoAssociations' => ['point:'.$point->getId()],
            ], [
                'photos' => [new UploadedFile($source, 'city-point.jpg', 'image/jpeg', null, true)],
            ]);

            self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
            $createdMedia = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(
                ['uploadedBy' => $admin],
                ['id' => 'DESC'],
            );
            self::assertInstanceOf(MediaAsset::class, $createdMedia);
            self::assertSame('Photo de visite rattachée au point', $createdMedia->getCaption());
            self::assertInstanceOf(CityVisitPointMedia::class, $this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
                'cityVisitPoint' => $point,
                'mediaAsset' => $createdMedia,
            ]));
            self::assertNull($this->entityManager()->getRepository(CityVisitDraftMedia::class)->findOneBy([
                'cityVisitDraft' => $cityVisit,
                'mediaAsset' => $createdMedia,
            ]));
        } finally {
            if ($createdMedia instanceof MediaAsset) {
                $this->removeGeneratedMediaFiles($createdMedia);
            }
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testCityVisitPhotoUploadRejectsForeignPointBeforeCreatingAnything(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $foreignCityVisit = $this->createCityVisitDraft($admin);
        $foreignPoint = $this->createCityVisitPoint($foreignCityVisit);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 96, 48);
        $mediaCount = $this->entityManager()->getRepository(MediaAsset::class)->count([]);
        $draftLinkCount = $this->entityManager()->getRepository(CityVisitDraftMedia::class)->count([]);
        $pointLinkCount = $this->entityManager()->getRepository(CityVisitPointMedia::class)->count([]);

        try {
            $client->request('POST', sprintf('/admin/studio/city-visits/%d/media/photos', $cityVisit->getId()), [
                '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visits/%d/media/photos', $cityVisit->getId())),
                'photoCaptions' => ['Tentative externe visite'],
                'photoImageTypes' => [ImageType::Standard->value],
                'photoAssociations' => ['point:'.$foreignPoint->getId()],
                'ajax' => '1',
            ], [
                'photos' => [new UploadedFile($source, 'foreign-city-point.jpg', 'image/jpeg', null, true)],
            ], ['HTTP_ACCEPT' => 'application/json']);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('ne fait pas partie', (string) $client->getResponse()->getContent());
            self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));
            self::assertSame($draftLinkCount, $this->entityManager()->getRepository(CityVisitDraftMedia::class)->count([]));
            self::assertSame($pointLinkCount, $this->entityManager()->getRepository(CityVisitPointMedia::class)->count([]));
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testCityVisitMediaPromotionAndDeletionUseRealCsrfForms(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $oldCover = $this->linkCityVisitMedia($cityVisit, $this->createImageMedia('Ancienne cover visite'), MediaRole::Cover, 0);
        $selected = $this->linkCityVisitMedia($cityVisit, $this->createImageMedia('Nouvelle cover visite'), MediaRole::Gallery, 1);
        $kept = $this->linkCityVisitMedia($cityVisit, $this->createImageMedia('Galerie visite conservée'), MediaRole::Gallery, 2);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visit-media/%d/update', $selected->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-media/%d/update', $selected->getId())),
            'title' => 'Nouvelle cover visite',
            'altText' => 'Image principale visite',
            'imageType' => ImageType::Standard->value,
            'association' => 'main',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $oldCover = $this->refresh($oldCover);
        $selected = $this->refresh($selected);
        self::assertSame(MediaRole::Gallery, $oldCover->getRole());
        self::assertSame(MediaRole::Cover, $selected->getRole());

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $selectedId = $selected->getId();
        $keptId = $kept->getId();
        $client->request('POST', sprintf('/admin/studio/city-visit-media/%d/delete', $selectedId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-media/%d/delete', $selectedId)),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertNull($this->entityManager()->find(CityVisitDraftMedia::class, $selectedId));
        self::assertNotNull($this->entityManager()->find(CityVisitDraftMedia::class, $keptId));
    }

    public function testCityVisitMediaCanMoveToOwnPointButRejectsForeignPointWithoutAnyMutation(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $point = $this->createCityVisitPoint($cityVisit, 43.61, 3.91);
        $foreignCityVisit = $this->createCityVisitDraft($admin);
        $foreignPoint = $this->createCityVisitPoint($foreignCityVisit, 44.61, 4.91);
        $movedMedia = $this->createImageMedia('Photo visite vers point');
        $foreignAttemptMedia = $this->createImageMedia('Photo visite reste generale');
        $foreignAttemptMedia
            ->setCaption('Légende initiale visite')
            ->setImageType(ImageType::Panorama);
        $this->persistAndFlush($foreignAttemptMedia);
        $movedLink = $this->linkCityVisitMedia($cityVisit, $movedMedia, MediaRole::Gallery, 0);
        $foreignAttemptLink = $this->linkCityVisitMedia($cityVisit, $foreignAttemptMedia, MediaRole::Cover, 1);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visit-media/%d/update', $movedLink->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-media/%d/update', $movedLink->getId())),
            'title' => 'Photo visite rattachee au point',
            'altText' => 'Vue depuis le point visite',
            'caption' => 'Caption point visite',
            'imageType' => ImageType::Standard->value,
            'association' => 'point:'.$point->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertNull($this->entityManager()->find(CityVisitDraftMedia::class, $movedLink->getId()));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $movedMedia->getId()));
        self::assertInstanceOf(CityVisitPointMedia::class, $this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $point,
            'mediaAsset' => $movedMedia,
        ]));
        self::assertNull($this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $foreignPoint,
            'mediaAsset' => $movedMedia,
        ]));

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $updateToken = $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-media/%d/update', $foreignAttemptLink->getId()));
        $point = $this->refresh($point);
        $foreignAttemptMedia = $this->refresh($foreignAttemptMedia);
        $existingPointLink = (new CityVisitPointMedia())
            ->setCityVisitPoint($point)
            ->setMediaAsset($foreignAttemptMedia);
        $point->addMediaLink($existingPointLink);
        $this->persistAndFlush($existingPointLink);
        $existingPointLinkId = $existingPointLink->getId();
        $client->request('POST', sprintf('/admin/studio/city-visit-media/%d/update', $foreignAttemptLink->getId()), [
            '_token' => $updateToken,
            'title' => 'Tentative point visite externe',
            'altText' => 'Alt point visite externe',
            'caption' => 'Légende externe visite',
            'imageType' => ImageType::Standard->value,
            'association' => 'point:'.$foreignPoint->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $foreignAttemptLink = $this->refresh($foreignAttemptLink);
        $foreignAttemptMedia = $this->refresh($foreignAttemptMedia);
        self::assertSame(MediaRole::Cover, $foreignAttemptLink->getRole());
        self::assertSame('Photo visite reste generale', $foreignAttemptMedia->getTitle());
        self::assertStringStartsWith('Texte alternatif ', (string) $foreignAttemptMedia->getAltText());
        self::assertSame('Légende initiale visite', $foreignAttemptMedia->getCaption());
        self::assertSame(ImageType::Panorama, $foreignAttemptMedia->getImageType());
        self::assertNotNull($this->entityManager()->find(CityVisitDraftMedia::class, $foreignAttemptLink->getId()));
        self::assertNotNull($this->entityManager()->find(CityVisitPointMedia::class, $existingPointLinkId));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $foreignAttemptMedia->getId()));
        self::assertNull($this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $foreignPoint,
            'mediaAsset' => $foreignAttemptMedia,
        ]));
    }

    public function testCityVisitMediaReassociationMovesExistingPointLinkWithoutDuplicate(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $firstPoint = $this->createCityVisitPoint($cityVisit, 43.61, 3.91);
        $secondPoint = $this->createCityVisitPoint($cityVisit, 43.62, 3.92);
        $media = $this->createImageMedia('Photo visite à réassocier');
        $draftLink = $this->linkCityVisitMedia($cityVisit, $media, MediaRole::Gallery, 0);
        $draftLinkId = $draftLink->getId();
        $firstPointLink = (new CityVisitPointMedia())->setCityVisitPoint($firstPoint)->setMediaAsset($media);
        $firstPoint->addMediaLink($firstPointLink);
        $this->persistAndFlush($firstPointLink);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/studio/city-visit-media/%d/update', $draftLinkId), [
            '_token' => $this->csrfTokenForClient($client, 'studio_city_visit_media_update_'.$draftLinkId),
            'title' => 'Photo visite réassociée',
            'imageType' => ImageType::Standard->value,
            'association' => 'point:'.$secondPoint->getId(),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertNull($this->entityManager()->find(CityVisitDraftMedia::class, $draftLinkId));
        self::assertNull($this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $firstPoint,
            'mediaAsset' => $media,
        ]));
        self::assertInstanceOf(CityVisitPointMedia::class, $this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $secondPoint,
            'mediaAsset' => $media,
        ]));
        self::assertSame(1, $this->entityManager()->getRepository(CityVisitPointMedia::class)->count(['mediaAsset' => $media]));
    }

    public function testCityVisitPointMediaRejectsUpdateAndDeleteTokensFromAnotherDraft(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $point = $this->createCityVisitPoint($cityVisit);
        $localMedia = $this->createImageMedia('Média point visite local');
        $localLink = (new CityVisitPointMedia())->setCityVisitPoint($point)->setMediaAsset($localMedia);
        $point->addMediaLink($localLink);
        $foreignCityVisit = $this->createCityVisitDraft($admin);
        $foreignPoint = $this->createCityVisitPoint($foreignCityVisit);
        $foreignMedia = $this->createImageMedia('Média point visite externe');
        $foreignLink = (new CityVisitPointMedia())->setCityVisitPoint($foreignPoint)->setMediaAsset($foreignMedia);
        $foreignPoint->addMediaLink($foreignLink);
        $this->persistAndFlush($localLink, $foreignLink);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/city-visit-point-media/%d/update', $foreignLink->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-point-media/%d/update', $localLink->getId())),
            'title' => 'Mutation visite externe',
            'altText' => 'Alt visite externe',
            'caption' => 'Légende visite externe',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#city-visit-point-%d', $foreignCityVisit->getId(), $foreignPoint->getId()));
        $foreignMedia = $this->refresh($foreignMedia);
        self::assertSame('Média point visite externe', $foreignMedia->getTitle());
        self::assertNotNull($this->entityManager()->find(CityVisitPointMedia::class, $foreignLink->getId()));

        $client->request('POST', sprintf('/admin/studio/city-visit-point-media/%d/delete', $foreignLink->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-point-media/%d/delete', $localLink->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#city-visit-point-%d', $foreignCityVisit->getId(), $foreignPoint->getId()));
        self::assertNotNull($this->entityManager()->find(CityVisitPointMedia::class, $foreignLink->getId()));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $foreignMedia->getId()));
        self::assertNotNull($this->entityManager()->find(CityVisitPointMedia::class, $localLink->getId()));
    }

    public function testCityVisitPointMediaUpdateAndDeleteKeepOtherPointAndMediaAsset(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $updatedPoint = $this->createCityVisitPoint($cityVisit, 43.50, 3.70, 1);
        $keptPoint = $this->createCityVisitPoint($cityVisit, 43.60, 3.80, 2);
        $updatedMedia = $this->createImageMedia('Photo point visite modifiee');
        $keptMedia = $this->createImageMedia('Photo autre point visite');
        $updatedLink = (new CityVisitPointMedia())->setCityVisitPoint($updatedPoint)->setMediaAsset($updatedMedia);
        $keptLink = (new CityVisitPointMedia())->setCityVisitPoint($keptPoint)->setMediaAsset($keptMedia);
        $updatedPoint->addMediaLink($updatedLink);
        $keptPoint->addMediaLink($keptLink);
        $this->persistAndFlush($updatedLink, $keptLink);
        $updatedLinkId = $updatedLink->getId();
        $keptLinkId = $keptLink->getId();
        $updatedMediaId = $updatedMedia->getId();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visit-point-media/%d/update', $updatedLinkId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-point-media/%d/update', $updatedLinkId)),
            'title' => 'Photo point visite renommee',
            'altText' => 'Alt point visite',
            'caption' => 'Caption point visite',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#city-visit-point-%d', $cityVisit->getId(), $updatedPoint->getId()));
        $updatedMedia = $this->refresh($updatedMedia);
        self::assertSame('Photo point visite renommee', $updatedMedia->getTitle());
        self::assertSame('Alt point visite', $updatedMedia->getAltText());
        self::assertSame('Caption point visite', $updatedMedia->getCaption());
        self::assertNotNull($this->entityManager()->find(CityVisitPointMedia::class, $keptLinkId));

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visit-point-media/%d/delete', $updatedLinkId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/city-visit-point-media/%d/delete', $updatedLinkId)),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#city-visit-point-%d', $cityVisit->getId(), $updatedPoint->getId()));
        self::assertNull($this->entityManager()->find(CityVisitPointMedia::class, $updatedLinkId));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $updatedMediaId));
        self::assertNotNull($this->entityManager()->find(CityVisitPointMedia::class, $keptLinkId));
        self::assertInstanceOf(CityVisitPointMedia::class, $this->entityManager()->getRepository(CityVisitPointMedia::class)->findOneBy([
            'cityVisitPoint' => $keptPoint,
            'mediaAsset' => $keptMedia,
        ]));
    }

    public function testMissingCityVisitMediaReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/studio/city-visit-media/2147483647/update', [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifiedAdminCanEditCityVisitWithGeographicCommuneOnly(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $cityVisit = $this->createCityVisitDraft($admin);
        $title = 'Visite fonctionnelle modifiée '.$this->uniqueToken('city-edit');
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $title,
            'destination' => '',
            'status' => CityVisitDraftStatus::Finished->value,
            'detectedCommuneName' => 'Perpignan',
            'detectedCommuneCode' => '66136',
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'notes' => 'Notes de visite.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        self::assertSame($title, $cityVisit->getTitle());
        self::assertSame(CityVisitDraftStatus::Finished, $cityVisit->getStatus());
        self::assertInstanceOf(Destination::class, $cityVisit->getGeographicDestination());
        self::assertSame($cityVisit->getGeographicDestination()->getId(), $cityVisit->getDestination()?->getId());
        self::assertSame('66136', $cityVisit->getGeographicDestination()->getCode());
        self::assertNotNull($cityVisit->getFinishedAt());
    }

    public function testCityVisitDraftWithoutLocationCanBeSaved(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Brouillon visite incomplet',
            'destination' => '',
            'status' => CityVisitDraftStatus::Draft->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'notes' => 'Notes conservées sans localisation.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        self::assertSame('Brouillon visite incomplet', $cityVisit->getTitle());
        self::assertSame(CityVisitDraftStatus::Draft, $cityVisit->getStatus());
        self::assertSame('Notes conservées sans localisation.', $cityVisit->getNotes());
        self::assertNull($cityVisit->getDestination());
        self::assertNull($cityVisit->getGeographicDestination());
        self::assertNull($cityVisit->getFinishedAt());
    }

    public function testCityVisitPublicationWithoutValidatedLocationIsRefusedButOtherDraftChangesAreSaved(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Visite prête sauf localisation',
            'destination' => '',
            'status' => CityVisitDraftStatus::Finished->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'notes' => 'Contenu éditorial sauvegardé.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Sélectionnez une commune valide dans les propositions avant de publier.',
            (string) $client->getResponse()->getContent(),
        );

        $cityVisit = $this->refresh($cityVisit);
        self::assertSame('Visite prête sauf localisation', $cityVisit->getTitle());
        self::assertSame('Contenu éditorial sauvegardé.', $cityVisit->getNotes());
        self::assertSame(CityVisitDraftStatus::Draft, $cityVisit->getStatus());
        self::assertNull($cityVisit->getDestination());
        self::assertNull($cityVisit->getGeographicDestination());
        self::assertNull($cityVisit->getFinishedAt());
    }

    public function testPartialCityVisitCommuneWarnsAndPreservesExistingLocation(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $existingCode = strtoupper(substr($this->uniqueToken('city-existing'), 0, 18));
        $geographicDestination = $this->createDestination(
            'Commune visite existante',
            DestinationType::City,
            code: $existingCode,
        );
        $geographicDestination->setDescription('Description géographique existante.');
        $cityVisit = $this->createCityVisitDraft($admin, $geographicDestination);
        $cityVisit
            ->setGeographicDestination($geographicDestination)
            ->setDetectedCommuneName('Commune visite existante')
            ->setDetectedCommuneCode($existingCode);
        $point = $this->createCityVisitPoint($cityVisit, 43.4455, 3.6677);
        $this->persistAndFlush($geographicDestination, $cityVisit);
        $destinationCount = $this->entityManager()->getRepository(Destination::class)->count([]);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Brouillon visite modifié avec commune partielle',
            'destination' => '',
            'status' => CityVisitDraftStatus::Finished->value,
            'detectedCommuneName' => 'Commune visite partielle',
            'detectedCommuneCode' => '',
            'locationLatitude' => '48.0000',
            'locationLongitude' => '3.0000',
            'notes' => 'Notes non géographiques conservées.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Sélectionnez une commune valide dans les propositions avant de publier.',
            (string) $client->getResponse()->getContent(),
        );

        $cityVisit = $this->refresh($cityVisit);
        $point = $this->refresh($point);
        self::assertSame('Brouillon visite modifié avec commune partielle', $cityVisit->getTitle());
        self::assertSame('Notes non géographiques conservées.', $cityVisit->getNotes());
        self::assertSame(CityVisitDraftStatus::Draft, $cityVisit->getStatus());
        self::assertSame($geographicDestination->getId(), $cityVisit->getDestination()?->getId());
        self::assertSame($geographicDestination->getId(), $cityVisit->getGeographicDestination()?->getId());
        self::assertSame('Commune visite existante', $cityVisit->getDetectedCommuneName());
        self::assertSame($existingCode, $cityVisit->getDetectedCommuneCode());
        self::assertSame(43.4455, $point->getLatitude());
        self::assertSame(3.6677, $point->getLongitude());
        self::assertSame('Description géographique existante.', $cityVisit->getDestination()?->getDescription());
        self::assertSame($destinationCount, $this->entityManager()->getRepository(Destination::class)->count([]));
    }

    public function testStudioCityVisitLocationPickerCreatesCommuneAndPrimaryPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $cityVisit = $this->createCityVisitDraft($admin);
        $communeCode = '66'.(string) random_int(100, 999);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('studio-city-visit-main-form', $crawler->filter('#city-visit-commune')->attr('form'));
        self::assertSame('studio-city-visit-main-form', $crawler->filter('#city-visit-commune-code')->attr('form'));
        self::assertSame('studio-city-visit-main-form', $crawler->filter('#city-visit-location-latitude')->attr('form'));
        self::assertGreaterThan(0, $crawler->filter('[data-validate-submit-form="studio-city-visit-main-form"][data-require-commune-for-validation="1"]')->count());

        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $cityVisit->getTitle(),
            'destination' => '',
            'status' => CityVisitDraftStatus::Draft->value,
            'detectedCommuneName' => 'Perpignan studio city',
            'detectedCommuneCode' => $communeCode,
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'locationCountry' => 'France',
            'locationDepartmentCode' => '66',
            'communeCenterLatitude' => '42.6986000',
            'communeCenterLongitude' => '2.8956000',
            'locationLatitude' => '42.7011111',
            'locationLongitude' => '2.9022222',
            'locationAccuracy' => '6',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        self::assertInstanceOf(Destination::class, $cityVisit->getGeographicDestination());
        self::assertSame($cityVisit->getGeographicDestination()->getId(), $cityVisit->getDestination()?->getId());
        self::assertSame($communeCode, $cityVisit->getGeographicDestination()->getCode());
        self::assertSame(42.6986, $cityVisit->getGeographicDestination()->getLatitude());
        self::assertSame('Perpignan studio city', $cityVisit->getDetectedCommuneName());
        self::assertSame($communeCode, $cityVisit->getDetectedCommuneCode());
        self::assertSame('Pyrenees-Orientales', $cityVisit->getDetectedDepartmentName());
        self::assertSame('Occitanie', $cityVisit->getDetectedRegionName());

        $points = $cityVisit->getPoints()->toArray();
        self::assertCount(1, $points);
        $point = $points[0];
        self::assertSame(CityVisitPointType::Start, $point->getType());
        self::assertSame(42.7011111, $point->getLatitude());
        self::assertSame(2.9022222, $point->getLongitude());
        self::assertSame(6.0, $point->getAccuracy());
        self::assertSame('Perpignan studio city', $point->getDetectedCommuneName());
        self::assertSame($communeCode, $point->getDetectedCommuneCode());
    }

    public function testStudioCityVisitLocationPickerUpdatesStartPointWithoutReorderingOtherPoints(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $firstPoint = $this->createCityVisitPoint($cityVisit, 43.10, 3.10, 1);
        $startPoint = $this->createCityVisitPoint($cityVisit, 43.20, 3.20, 2);
        $firstPoint->setType(CityVisitPointType::Monument)->setTitle('Monument conserve');
        $startPoint->setType(CityVisitPointType::Start)->setTitle('Départ conserve');
        $this->persistAndFlush($firstPoint, $startPoint);
        $communeCode = '66'.(string) random_int(100, 999);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $cityVisit->getTitle(),
            'destination' => '',
            'status' => CityVisitDraftStatus::Draft->value,
            'detectedCommuneName' => 'Ville principale conservee',
            'detectedCommuneCode' => $communeCode,
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'locationCountry' => 'France',
            'locationDepartmentCode' => '66',
            'communeCenterLatitude' => '42.7000000',
            'communeCenterLongitude' => '2.9000000',
            'locationLatitude' => '42.7111111',
            'locationLongitude' => '2.9222222',
            'locationAccuracy' => '3',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        $firstPoint = $this->refresh($firstPoint);
        $startPoint = $this->refresh($startPoint);
        self::assertCount(2, $cityVisit->getPoints());
        self::assertSame(CityVisitPointType::Monument, $firstPoint->getType());
        self::assertSame(1, $firstPoint->getPosition());
        self::assertSame(43.10, $firstPoint->getLatitude());
        self::assertSame(3.10, $firstPoint->getLongitude());
        self::assertSame(CityVisitPointType::Start, $startPoint->getType());
        self::assertSame(2, $startPoint->getPosition());
        self::assertSame(42.7111111, $startPoint->getLatitude());
        self::assertSame(2.9222222, $startPoint->getLongitude());
        self::assertSame(3.0, $startPoint->getAccuracy());
        self::assertSame('Ville principale conservee', $startPoint->getDetectedCommuneName());
        self::assertSame($communeCode, $startPoint->getDetectedCommuneCode());
    }

    public function testStudioCityVisitLocationPickerReplacesExistingCommuneAndPublicDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $country = $this->createDestination('France city replace', DestinationType::Country, null, 'FR');
        $region = $this->createDestination('Occitanie city replace', DestinationType::Region, $country, '76');
        $herault = $this->createDestination('Herault city replace', DestinationType::Department, $region, '34');
        $aude = $this->createDestination('Aude city replace', DestinationType::Department, $region, '11');
        $beziersCode = '34'.(string) random_int(100, 999);
        $narbonneCode = '11'.(string) random_int(100, 999);
        $beziers = $this->createDestination('Beziers city replace', DestinationType::City, $herault, $beziersCode);
        $narbonne = $this->createDestination('Narbonne city replace', DestinationType::City, $aude, $narbonneCode);
        $cityVisit = $this->createCityVisitDraft($admin, $beziers);
        $cityVisit
            ->setGeographicDestination($beziers)
            ->setDetectedCommuneName('Beziers city replace')
            ->setDetectedCommuneCode($beziersCode)
            ->setDetectedDepartmentName('Herault city replace')
            ->setDetectedRegionName('Occitanie city replace');
        $point = $this->createCityVisitPoint($cityVisit, 43.3442, 3.2158);
        $point
            ->setDetectedCommuneName('Beziers city replace')
            ->setDetectedCommuneCode($beziersCode)
            ->setDetectedDepartmentName('Herault city replace')
            ->setDetectedRegionName('Occitanie city replace')
            ->setAccuracy(7.0);
        $this->persistAndFlush($cityVisit, $point);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('Beziers city replace', $this->inputValue($crawler, '#city-visit-commune'));
        self::assertSame($beziersCode, $this->inputValue($crawler, '#city-visit-commune-code'));
        self::assertSame('43.3442', $this->inputValue($crawler, '#city-visit-location-latitude'));
        self::assertSame('3.2158', $this->inputValue($crawler, '#city-visit-location-longitude'));

        $title = (string) $cityVisit->getTitle();
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $title,
            'destination' => (string) $beziers->getId(),
            'status' => CityVisitDraftStatus::Finished->value,
            'detectedCommuneName' => 'Narbonne city replace',
            'detectedCommuneCode' => $narbonneCode,
            'detectedDepartmentName' => 'Aude city replace',
            'detectedRegionName' => 'Occitanie city replace',
            'locationCountry' => 'France',
            'locationDepartmentCode' => '11',
            'communeCenterLatitude' => '43.1843000',
            'communeCenterLongitude' => '3.0031000',
            'locationLatitude' => '43.1838000',
            'locationLongitude' => '3.0042000',
            'locationAccuracy' => '12',
            'notes' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        $point = $this->refresh($point);
        self::assertSame($narbonne->getId(), $cityVisit->getDestination()?->getId());
        self::assertSame($narbonne->getId(), $cityVisit->getGeographicDestination()?->getId());
        self::assertNotSame($beziers->getId(), $cityVisit->getDestination()?->getId());
        self::assertSame('Narbonne city replace', $cityVisit->getDetectedCommuneName());
        self::assertSame($narbonneCode, $cityVisit->getDetectedCommuneCode());
        self::assertSame('Aude city replace', $cityVisit->getDetectedDepartmentName());
        self::assertSame('Occitanie city replace', $cityVisit->getDetectedRegionName());
        self::assertSame(43.1838, $point->getLatitude());
        self::assertSame(3.0042, $point->getLongitude());
        self::assertSame(12.0, $point->getAccuracy());
        self::assertSame('Narbonne city replace', $point->getDetectedCommuneName());
        self::assertSame($narbonneCode, $point->getDetectedCommuneCode());

        $client->request('GET', sprintf('/destinations/%s', $narbonne->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $title);

        $client->request('GET', sprintf('/destinations/%s', $beziers->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($title, $client->getResponse()->getContent() ?: '');
    }

    public function testPublishedCityVisitCanReturnToDraftWithoutLosingItsGeographicLocationOrPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $communeCode = strtoupper(substr($this->uniqueToken('city-draft'), 0, 18));
        $geographicDestination = $this->createDestination('Commune visite retour brouillon', DestinationType::City, code: $communeCode);
        $cityVisit = $this->createPublishedCityVisit($admin, $geographicDestination);
        $cityVisit
            ->setGeographicDestination($geographicDestination)
            ->setDetectedCommuneName('Commune visite retour brouillon')
            ->setDetectedCommuneCode($communeCode);
        $point = $this->createCityVisitPoint($cityVisit, 43.1234, 3.5678);
        $finishedAt = $cityVisit->getFinishedAt();
        $this->persistAndFlush($cityVisit);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $cityVisit->getTitle(),
            'destination' => (string) $geographicDestination->getId(),
            'status' => CityVisitDraftStatus::Draft->value,
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'notes' => 'Retour en préparation.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        $point = $this->refresh($point);
        self::assertSame(CityVisitDraftStatus::Draft, $cityVisit->getStatus());
        self::assertSame($geographicDestination->getId(), $cityVisit->getDestination()?->getId());
        self::assertSame($geographicDestination->getId(), $cityVisit->getGeographicDestination()?->getId());
        self::assertSame('Commune visite retour brouillon', $cityVisit->getDetectedCommuneName());
        self::assertSame(43.1234, $point->getLatitude());
        self::assertSame(3.5678, $point->getLongitude());
        self::assertSame($finishedAt?->getTimestamp(), $cityVisit->getFinishedAt()?->getTimestamp());
        self::assertSame('Retour en préparation.', $cityVisit->getDestination()?->getDescription());
    }

    public function testIncompleteCityVisitGpsRefusesPublicationWithoutPersistingPartialChanges(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $editorialDestination = $this->createDestination('Destination visite avant erreur');
        $cityVisit = $this->createCityVisitDraft($admin, $editorialDestination);
        $originalTitle = (string) $cityVisit->getTitle();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Titre visite qui ne doit pas être persisté',
            'destination' => (string) $editorialDestination->getId(),
            'status' => CityVisitDraftStatus::Finished->value,
            'detectedCommuneName' => 'Commune visite incomplète',
            'detectedCommuneCode' => strtoupper(substr($this->uniqueToken('city-error'), 0, 18)),
            'detectedDepartmentName' => 'Département test',
            'detectedRegionName' => 'Région test',
            'locationLatitude' => '43.1234',
            'locationLongitude' => '',
            'notes' => 'Notes qui ne doivent pas être persistées.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit#section-publication', $cityVisit->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Le point GPS doit contenir une latitude et une longitude valides.',
            (string) $client->getResponse()->getContent(),
        );

        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(CityVisitDraft::class, $cityVisit->getId());
        self::assertInstanceOf(CityVisitDraft::class, $stored);
        self::assertSame($originalTitle, $stored->getTitle());
        self::assertSame(CityVisitDraftStatus::Draft, $stored->getStatus());
        self::assertSame($editorialDestination->getId(), $stored->getDestination()?->getId());
        self::assertNull($stored->getGeographicDestination());
        self::assertNull($stored->getNotes());
        self::assertNull($stored->getFinishedAt());
        self::assertNull($stored->getDestination()?->getDescription());
    }

    private function removeGeneratedMediaFiles(MediaAsset $media): void
    {
        $paths = [$media->getFilePath(), $media->getThumbnailPath()];
        $variants = $media->getVariants() ?? [];
        array_walk_recursive($variants, static function (mixed $value) use (&$paths): void {
            if (is_string($value)) {
                $paths[] = $value;
            }
        });

        foreach (array_unique(array_filter($paths, 'is_string')) as $path) {
            if (!str_starts_with($path, '/uploads/media/')) {
                continue;
            }

            $absolutePath = dirname(__DIR__, 2).'/public/'.ltrim($path, '/');
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
        }
    }
}
