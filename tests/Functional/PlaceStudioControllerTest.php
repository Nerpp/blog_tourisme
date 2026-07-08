<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Entity\PlaceMedia;
use App\Enum\CategoryType;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Enum\VideoType;
use App\Tests\Support\TestImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PlaceStudioControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromPlaceEdit(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromPlaceEdit(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenPlaceEdit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $place = $this->createPlace();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testVerifiedAdminGetsNotFoundForMissingPlace(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin/studio/places/2147483647/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPlacePhotoUploadAccessIsProtectedAndEmptyUploadIsRejected(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();

        $client->request('POST', sprintf('/admin/studio/places/%d/media/photos', $place->getId()));
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('POST', sprintf('/admin/studio/places/%d/media/photos', $place->getId()));
        self::assertResponseRedirects('/');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $mediaRepository = $this->entityManager()->getRepository(MediaAsset::class);
        $before = $mediaRepository->count([]);

        $client->request('POST', sprintf('/admin/studio/places/%d/media/photos', $place->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/places/%d/media/photos', $place->getId())),
            'ajax' => '1',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Aucune photo valide', (string) $client->getResponse()->getContent());
        self::assertSame($before, $mediaRepository->count([]));
    }

    public function testPlacePhotoUploadHtmlFlowRejectsExpiredAndEmptyFormsThenCreatesCover(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/places/%d/media/photos', $place->getId());

        $client->request('POST', $url, ['_token' => 'bad-token']);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', $url, [
            '_token' => $this->tokenFromFormAction($crawler, $url),
        ]);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 96, 48);
        $createdMedia = null;

        try {
            $client->request('POST', $url, [
                '_token' => $this->tokenFromFormAction($crawler, $url),
                'photoCaptions' => ['Couverture du repérage'],
                'photoImageTypes' => [ImageType::Standard->value],
                'photoAssociations' => ['main'],
            ], [
                'photos' => [new UploadedFile($source, 'place-cover.jpg', 'image/jpeg', null, true)],
            ]);

            self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
            $createdMedia = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(
                ['uploadedBy' => $admin],
                ['id' => 'DESC'],
            );
            self::assertInstanceOf(MediaAsset::class, $createdMedia);
            self::assertSame(MediaType::Image, $createdMedia->getMediaType());
            self::assertSame('Couverture du repérage', $createdMedia->getCaption());
            $link = $this->entityManager()->getRepository(PlaceMedia::class)->findOneBy([
                'place' => $place,
                'mediaAsset' => $createdMedia,
            ]);
            self::assertInstanceOf(PlaceMedia::class, $link);
            self::assertSame(MediaRole::Cover, $link->getRole());
            self::assertSame($createdMedia->getId(), $this->refresh($place)->getFeaturedImage()?->getId());
        } finally {
            if ($createdMedia instanceof MediaAsset) {
                $this->removeGeneratedMediaFiles($createdMedia);
            }
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testPlacePhotoUploadRejectsInvalidImageWithoutLeavingMediaOrFile(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $url = sprintf('/admin/studio/places/%d/media/photos/bulk-upload', $place->getId());
        $source = tempnam(sys_get_temp_dir(), 'place-invalid-image-');
        self::assertIsString($source);
        file_put_contents($source, 'not an image');
        $mediaCount = $this->entityManager()->getRepository(MediaAsset::class)->count([]);

        try {
            $client->request('POST', $url, [
                '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/places/%d/media/photos', $place->getId())),
                'photoImageTypes' => [ImageType::Standard->value],
            ], [
                'photos' => [new UploadedFile($source, 'fake.jpg', 'image/jpeg', null, true)],
            ], ['HTTP_ACCEPT' => 'application/json']);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('error', (string) $client->getResponse()->getContent());
            self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));

            $client->request('POST', $url, [
                '_token' => $this->csrfTokenForClient($client, 'studio_place_photos_'.$place->getId()),
                'photoImageTypes' => [ImageType::Standard->value],
            ], [
                'photos' => [new UploadedFile($source, 'fake-html.jpg', 'image/jpeg', null, true)],
            ]);

            self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
            self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testPlacePhotoUploadRateLimitReturnsStructuredJsonAfterBucketExhaustion(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/places/%d/media/photos/bulk-upload', $place->getId());
        $token = $this->csrfTokenForClient($client, 'studio_place_photos_'.$place->getId());

        for ($attempt = 1; $attempt <= 30; ++$attempt) {
            $client->request('POST', $url, ['_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(422, sprintf('Tentative autorisée n°%d', $attempt));
        }

        $client->request('POST', $url, ['_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(429);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertStringContainsString('Trop d’envois', (string) $payload['error']);
        self::assertSame(0, $this->entityManager()->getRepository(MediaAsset::class)->count(['uploadedBy' => $admin]));
    }

    public function testPlacePhotoUploadReturnsJsonForExpiredFormAndRedirectsOversizedHtmlSelection(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/places/%d/media/photos/bulk-upload', $place->getId());

        $client->request('POST', $url, ['_token' => 'bad-token'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(419);
        $expiredPayload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('expiré', (string) $expiredPayload['error']);

        $paths = [];
        try {
            $files = [];
            for ($index = 0; $index <= 50; ++$index) {
                $path = sprintf('%s/place-bulk-limit-%s-%02d.jpg', sys_get_temp_dir(), bin2hex(random_bytes(4)), $index);
                file_put_contents($path, 'selection limit fixture');
                $paths[] = $path;
                $files[] = new UploadedFile($path, sprintf('place-%02d.jpg', $index), 'image/jpeg', null, true);
            }
            $client->request('POST', $url, [
                '_token' => $this->csrfTokenForClient($client, 'studio_place_photos_'.$place->getId()),
            ], ['photos' => $files]);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertSame(0, $this->entityManager()->getRepository(PlaceMedia::class)->count(['place' => $place]));
    }

    public function testPlacePanoramaUploadPersistsEquirectangularMetadata(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $url = sprintf('/admin/studio/places/%d/media/photos', $place->getId());
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 400, 200);
        $createdMedia = null;

        try {
            $client->request('POST', $url, [
                '_token' => $this->tokenFromFormAction($crawler, $url),
                'photoCaptions' => ['Panorama terrain 360°'],
                'photoImageTypes' => [ImageType::Degree360->value],
                'photoAssociations' => ['gallery'],
                'ajax' => '1',
            ], [
                'photos' => [new UploadedFile($source, 'place-panorama.jpg', 'image/jpeg', null, true)],
            ], ['HTTP_ACCEPT' => 'application/json']);

            self::assertResponseIsSuccessful();
            $createdMedia = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(
                ['uploadedBy' => $admin, 'imageType' => ImageType::Degree360],
                ['id' => 'DESC'],
            );
            self::assertInstanceOf(MediaAsset::class, $createdMedia);
            self::assertSame('equirectangular', $createdMedia->getProjection());
            self::assertSame(400, $createdMedia->getWidth());
            self::assertSame(200, $createdMedia->getHeight());
            self::assertTrue($createdMedia->getMetadata()['metadataSanitized'] ?? false);
            $link = $this->entityManager()->getRepository(PlaceMedia::class)->findOneBy(['place' => $place, 'mediaAsset' => $createdMedia]);
            self::assertInstanceOf(PlaceMedia::class, $link);
            self::assertSame(MediaRole::Gallery, $link->getRole());
        } finally {
            if ($createdMedia instanceof MediaAsset) {
                $this->removeGeneratedMediaFiles($createdMedia);
            }
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testPlaceVideoUploadRequiresCsrfAndUrlThenNormalizesLocalType(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/places/%d/media/video', $place->getId());
        $before = $this->entityManager()->getRepository(MediaAsset::class)->count([]);

        $client->request('POST', $url, ['_token' => 'bad-token', 'externalUrl' => 'https://example.test/video']);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertSame($before, $this->entityManager()->getRepository(MediaAsset::class)->count([]));

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $token = $this->tokenFromFormAction($crawler, $url);
        $client->request('POST', $url, ['_token' => $token, 'externalUrl' => '']);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertSame($before, $this->entityManager()->getRepository(MediaAsset::class)->count([]));

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', $url, [
            '_token' => $this->tokenFromFormAction($crawler, $url),
            'title' => 'Vidéo repérage normalisée',
            'caption' => 'Vidéo sans appel externe',
            'videoType' => VideoType::Local->value,
            'externalUrl' => 'https://example.test/place-video',
            'usage' => 'main',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $media = $this->entityManager()->getRepository(MediaAsset::class)->findOneBy(['title' => 'Vidéo repérage normalisée']);
        self::assertInstanceOf(MediaAsset::class, $media);
        self::assertSame(MediaType::Video, $media->getMediaType());
        self::assertSame(VideoType::External, $media->getVideoType());
        self::assertSame('https://example.test/place-video', $media->getExternalUrl());
        $link = $this->entityManager()->getRepository(PlaceMedia::class)->findOneBy(['place' => $place, 'mediaAsset' => $media]);
        self::assertInstanceOf(PlaceMedia::class, $link);
        self::assertSame(MediaRole::Gallery, $link->getRole());
    }

    public function testPlaceVideoUpdateRequiresCsrfAndRefreshesYoutubeThumbnail(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $video = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setTitle('Vidéo repérage initiale')
            ->setExternalUrl('https://example.test/initial-video');
        $this->persistAndFlush($video);
        $link = $this->linkPlaceMedia($place, $video, MediaRole::Gallery, 0);
        $client->loginUser($admin);
        $url = sprintf('/admin/studio/place-media/%d/update', $link->getId());

        $client->request('POST', $url, [
            '_token' => 'bad-token',
            'title' => 'Modification refusée',
        ]);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertSame('Vidéo repérage initiale', $this->refresh($video)->getTitle());

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', $url, [
            '_token' => $this->tokenFromFormAction($crawler, $url),
            'title' => 'Vidéo repérage actualisée',
            'caption' => 'Nouvelle légende vidéo',
            'videoType' => VideoType::Youtube->value,
            'externalUrl' => 'https://www.youtube.com/watch?v=9bZkp7q19f0',
            'usage' => 'gallery',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $video = $this->refresh($video);
        self::assertSame('Vidéo repérage actualisée', $video->getTitle());
        self::assertSame('Nouvelle légende vidéo', $video->getCaption());
        self::assertSame(VideoType::Youtube, $video->getVideoType());
        self::assertSame('https://www.youtube.com/watch?v=9bZkp7q19f0', $video->getExternalUrl());
        self::assertSame('https://img.youtube.com/vi/9bZkp7q19f0/hqdefault.jpg', $video->getThumbnailPath());
        self::assertSame(MediaRole::Gallery, $this->refresh($link)->getRole());
    }

    public function testPlaceMediaPromotionUpdatesFeaturedImageAndDemotesOldCover(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $oldMedia = $this->createImageMedia('Ancienne cover lieu');
        $newMedia = $this->createImageMedia('Nouvelle cover lieu');
        $oldCover = $this->linkPlaceMedia($place, $oldMedia, MediaRole::Cover, 0);
        $newCover = $this->linkPlaceMedia($place, $newMedia, MediaRole::Gallery, 1);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/place-media/%d/update', $newCover->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/place-media/%d/update', $newCover->getId())),
            'title' => 'Nouvelle cover lieu',
            'altText' => 'Image principale lieu',
            'caption' => '',
            'imageType' => ImageType::Standard->value,
            'usage' => 'main',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $oldCover = $this->refresh($oldCover);
        $newCover = $this->refresh($newCover);
        $place = $this->refresh($place);
        self::assertSame(MediaRole::Gallery, $oldCover->getRole());
        self::assertSame(MediaRole::Cover, $newCover->getRole());
        self::assertSame($newMedia->getId(), $place->getFeaturedImage()?->getId());
    }

    public function testPlaceMediaDeletionRequiresCsrfAndClearsFeaturedImageOnlyForDeletedCover(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $coverMedia = $this->createImageMedia('Cover lieu supprimée');
        $cover = $this->linkPlaceMedia($place, $coverMedia, MediaRole::Cover, 0);
        $kept = $this->linkPlaceMedia($place, $this->createImageMedia('Galerie lieu conservée'), MediaRole::Gallery, 1);
        $coverId = $cover->getId();
        $coverMediaId = $coverMedia->getId();
        $keptId = $kept->getId();
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/studio/place-media/%d/delete', $coverId), ['_token' => 'invalid-token']);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertNotNull($this->entityManager()->find(PlaceMedia::class, $coverId));

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/place-media/%d/delete', $coverId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/place-media/%d/delete', $coverId)),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertNull($this->entityManager()->find(PlaceMedia::class, $coverId));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $coverMediaId));
        self::assertNotNull($this->entityManager()->find(PlaceMedia::class, $keptId));
        $place = $this->refresh($place);
        self::assertNull($place->getFeaturedImage());
    }

    public function testMissingPlaceMediaReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/studio/place-media/2147483647/delete', [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifiedAdminCanEditMinimalPlace(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $destination = $this->createDestination();
        $category = $this->createCategory(CategoryType::Place);
        $place = $this->createPlace();
        $token = $this->uniqueToken('place-edit');
        $name = 'Lieu fonctionnel modifié '.$token;
        $slug = 'lieu-fonctionnel-modifie-'.$token;
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $name,
            'slug' => $slug,
            'destination' => (string) $destination->getId(),
            'category' => (string) $category->getId(),
            'status' => ContentStatus::Published->value,
            'action' => 'save',
            'shortDescription' => 'Description courte.',
            'description' => 'Description longue de test.',
            'address' => '1 rue du Test',
            'latitude' => '42.6986',
            'longitude' => '2.8956',
            'visitDurationMinutes' => '45',
            'difficulty' => PlaceDifficulty::Easy->value,
            'priceType' => PriceType::Free->value,
            'seoTitle' => 'SEO lieu test',
            'seoDescription' => 'SEO description lieu test.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $place = $this->refresh($place);
        self::assertSame($name, $place->getName());
        self::assertSame($slug, $place->getSlug());
        self::assertSame($destination->getId(), $place->getDestination()?->getId());
        self::assertSame($category->getId(), $place->getCategory()?->getId());
        self::assertSame(ContentStatus::Published, $place->getStatus());
        self::assertSame(42.6986, $place->getLatitude());
        self::assertSame(2.8956, $place->getLongitude());
        self::assertSame(45, $place->getVisitDurationMinutes());
    }

    public function testPlaceEditRejectsExpiredFormAndRestoresFeaturedGalleryAsCover(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $media = $this->createImageMedia('Image vedette sans rôle cover');
        $link = $this->linkPlaceMedia($place, $media, MediaRole::Gallery, 0);
        $place->setFeaturedImage($media);
        $this->persistAndFlush($place);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(MediaRole::Cover, $this->refresh($link)->getRole());

        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => 'bad-token',
            'name' => 'Nom refusé',
        ]);
        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertNotSame('Nom refusé', $this->refresh($place)->getName());
    }

    public function testZeroCoordinatesAreAcceptedAsValidValues(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $place->getName(),
            'slug' => $place->getSlug(),
            'latitude' => '0',
            'longitude' => '0',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $place = $this->refresh($place);
        self::assertSame(0.0, $place->getLatitude());
        self::assertSame(0.0, $place->getLongitude());
    }

    /**
     * @return iterable<string, array{
     *     latitude: string,
     *     longitude: string,
     *     expectedMessage: string
     * }>
     */
    public static function invalidCoordinateProvider(): iterable
    {
        yield 'non numeric latitude' => [
            'latitude' => 'nord',
            'longitude' => '2.8956',
            'expectedMessage' => 'La latitude doit être un nombre valide compris entre -90 et 90.',
        ];

        yield 'non numeric longitude' => [
            'latitude' => '42.6986',
            'longitude' => 'est',
            'expectedMessage' => 'La longitude doit être un nombre valide compris entre -180 et 180.',
        ];

        yield 'latitude above range' => [
            'latitude' => '90.0001',
            'longitude' => '2.8956',
            'expectedMessage' => 'La latitude doit être comprise entre -90 et 90.',
        ];

        yield 'longitude below range' => [
            'latitude' => '42.6986',
            'longitude' => '-180.0001',
            'expectedMessage' => 'La longitude doit être comprise entre -180 et 180.',
        ];

        yield 'NaN latitude' => [
            'latitude' => 'NaN',
            'longitude' => '2.8956',
            'expectedMessage' => 'La latitude doit être un nombre valide compris entre -90 et 90.',
        ];

        yield 'infinite longitude' => [
            'latitude' => '42.6986',
            'longitude' => 'Infinity',
            'expectedMessage' => 'La longitude doit être un nombre valide compris entre -180 et 180.',
        ];

        yield 'overflowing finite latitude' => [
            'latitude' => '1e309',
            'longitude' => '2.8956',
            'expectedMessage' => 'La latitude doit être comprise entre -90 et 90.',
        ];

        yield 'malformed longitude' => [
            'latitude' => '42.6986',
            'longitude' => '2,8,9',
            'expectedMessage' => 'La longitude doit être un nombre valide compris entre -180 et 180.',
        ];
    }

    #[DataProvider('invalidCoordinateProvider')]
    public function testInvalidCoordinatesAreRejectedWithoutPersistingZeroOrOtherChanges(
        string $latitude,
        string $longitude,
        string $expectedMessage,
    ): void {
        $client = static::createClient();
        $place = $this->createPlace();
        $originalName = (string) $place->getName();
        $place
            ->setLatitude(12.3456)
            ->setLongitude(67.8901);
        $this->persistAndFlush($place);
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => 'Nom qui ne doit pas être persisté',
            'slug' => 'slug-qui-ne-doit-pas-etre-persiste',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($expectedMessage, (string) $client->getResponse()->getContent());

        $place = $this->refresh($place);
        self::assertSame($originalName, $place->getName());
        self::assertSame(12.3456, $place->getLatitude());
        self::assertSame(67.8901, $place->getLongitude());
        self::assertNotSame(0.0, $place->getLatitude());
        self::assertNotSame(0.0, $place->getLongitude());
    }

    public function testBlankCoordinatesClearOptionalCoordinates(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();
        $place
            ->setLatitude(42.6986)
            ->setLongitude(2.8956);
        $this->persistAndFlush($place);
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $place->getName(),
            'slug' => $place->getSlug(),
            'latitude' => ' ',
            'longitude' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $place = $this->refresh($place);
        self::assertNull($place->getLatitude());
        self::assertNull($place->getLongitude());
    }

    public function testPublishAndDraftActionsNormalizeDuplicateCovers(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $laterCover = $this->linkPlaceMedia($place, $this->createImageMedia('Cover tardive'), MediaRole::Cover, 5);
        $firstCover = $this->linkPlaceMedia($place, $this->createImageMedia('Cover prioritaire'), MediaRole::Cover, 1);
        $place->setFeaturedImage($laterCover->getMediaAsset());
        $this->persistAndFlush($place);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $place->getName(),
            'slug' => $place->getSlug(),
            'status' => ContentStatus::Draft->value,
            'action' => 'publish',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $place = $this->refresh($place);
        $firstCover = $this->refresh($firstCover);
        $laterCover = $this->refresh($laterCover);
        self::assertSame(ContentStatus::Published, $place->getStatus());
        self::assertNotNull($place->getPublishedAt());
        self::assertSame(MediaRole::Cover, $firstCover->getRole());
        self::assertSame(MediaRole::Gallery, $laterCover->getRole());
        self::assertSame($firstCover->getMediaAsset()?->getId(), $place->getFeaturedImage()?->getId());
        $publishedAt = $place->getPublishedAt();

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $place->getName(),
            'slug' => $place->getSlug(),
            'status' => ContentStatus::Published->value,
            'action' => 'draft',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $place = $this->refresh($place);
        self::assertSame(ContentStatus::Draft, $place->getStatus());
        self::assertSame($publishedAt?->getTimestamp(), $place->getPublishedAt()?->getTimestamp());
    }

    private function removeGeneratedMediaFiles(MediaAsset $media): void
    {
        $paths = [$media->getFilePath(), $media->getThumbnailPath()];
        $fileMetadata = [$media->getVariants() ?? [], $media->getMetadata() ?? []];
        array_walk_recursive($fileMetadata, static function (mixed $value) use (&$paths): void {
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
