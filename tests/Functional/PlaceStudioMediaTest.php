<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Entity\PlaceMedia;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Tests\Support\TestImageFactory;

final class PlaceStudioMediaTest extends FunctionalTestCase
{
    public function testJsonPhotoUploadCreatesOrderedCoverAssociatedWithPlace(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $this->linkPlaceMedia($place, $this->createImageMedia('Image existante'), MediaRole::Gallery, 4);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $source = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 120, 80);
        $upload = TestImageFactory::createUploadedFile($source, 'Nouvelle vue.jpg', 'image/jpeg');

        $client->request(
            'POST',
            sprintf('/admin/studio/places/%d/media/photos', $place->getId()),
            [
                '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/places/%d/media/photos', $place->getId())),
                'ajax' => '1',
                'photoCaptions' => ['Vue principale'],
                'photoImageTypes' => [ImageType::Standard->value],
                'photoAssociations' => ['main'],
            ],
            ['photos' => [$upload]],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ],
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['sent']);
        self::assertSame(0, $payload['failed']);
        self::assertSame(1, $payload['total']);

        $links = $this->entityManager()->getRepository(PlaceMedia::class)->findBy(
            ['place' => $place],
            ['position' => 'ASC'],
        );
        self::assertCount(2, $links);
        $createdLink = $links[1];
        self::assertSame(5, $createdLink->getPosition());
        self::assertSame(MediaRole::Cover, $createdLink->getRole());
        $createdMedia = $createdLink->getMediaAsset();
        self::assertInstanceOf(MediaAsset::class, $createdMedia);
        self::assertSame(MediaType::Image, $createdMedia->getMediaType());
        self::assertSame(ImageType::Standard, $createdMedia->getImageType());
        self::assertSame('Vue principale', $createdMedia->getCaption());
        self::assertNull($createdMedia->getFilePath());
        self::assertIsArray($createdMedia->getVariants());
        foreach (['thumb', 'mobile', 'medium', 'large'] as $size) {
            self::assertArrayHasKey($size, $createdMedia->getVariants());
            self::assertSame(
                ['webp', 'width', 'height'],
                array_keys($createdMedia->getVariants()[$size]),
            );
        }
        self::assertIsArray($createdMedia->getMetadata());
        $deletedMasterPath = $createdMedia->getMetadata()['deletedPublicMasterPath'] ?? null;
        self::assertIsString($deletedMasterPath);
        self::assertFileDoesNotExist(TestImageFactory::projectDir().'/public'.$deletedMasterPath);
        self::assertSame($createdMedia->getId(), $this->refresh($place)->getFeaturedImage()?->getId());
    }

    public function testAddingYoutubeVideoAlwaysUsesGalleryAndGeneratesThumbnail(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $url = 'https://www.youtube.com/watch?v=abcDEF12345';

        $client->request('POST', sprintf('/admin/studio/places/%d/media/video', $place->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/studio/places/%d/media/video', $place->getId())),
            'externalUrl' => $url,
            'videoType' => VideoType::Youtube->value,
            'title' => '',
            'caption' => 'Présentation vidéo',
            'usage' => 'main',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $link = $this->entityManager()->getRepository(PlaceMedia::class)->findOneBy(['place' => $place]);
        self::assertInstanceOf(PlaceMedia::class, $link);
        self::assertSame(MediaRole::Gallery, $link->getRole());
        self::assertSame(0, $link->getPosition());
        $media = $link->getMediaAsset();
        self::assertInstanceOf(MediaAsset::class, $media);
        self::assertSame(MediaType::Video, $media->getMediaType());
        self::assertSame(VideoType::Youtube, $media->getVideoType());
        self::assertSame($url, $media->getExternalUrl());
        self::assertSame('https://img.youtube.com/vi/abcDEF12345/hqdefault.jpg', $media->getThumbnailPath());
        self::assertNotSame('', trim((string) $media->getTitle()));
        self::assertNull($this->refresh($place)->getFeaturedImage());
    }

    public function testVideoUpdateRejectsTokenFromAnotherLinkThenNormalizesChangedVideo(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $media = (new MediaAsset())
            ->setTitle('Ancienne vidéo')
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://youtu.be/oldVideo123')
            ->setThumbnailPath('https://img.youtube.com/vi/oldVideo123/hqdefault.jpg');
        $this->persistAndFlush($media);
        $link = $this->linkPlaceMedia($place, $media, MediaRole::Gallery, 0);
        $otherPlace = $this->createPlace();
        $otherMedia = (new MediaAsset())
            ->setTitle('Autre vidéo')
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setExternalUrl('https://example.test/video');
        $this->persistAndFlush($otherMedia);
        $otherLink = $this->linkPlaceMedia($otherPlace, $otherMedia, MediaRole::Gallery, 0);
        $client->loginUser($admin);
        $otherCrawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $otherPlace->getId()));
        self::assertResponseIsSuccessful();
        $otherToken = $this->tokenFromFormAction(
            $otherCrawler,
            sprintf('/admin/studio/place-media/%d/update', $otherLink->getId()),
        );
        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();
        $validToken = $this->tokenFromFormAction(
            $crawler,
            sprintf('/admin/studio/place-media/%d/update', $link->getId()),
        );

        $client->request('POST', sprintf('/admin/studio/place-media/%d/update', $link->getId()), [
            '_token' => $otherToken,
            'title' => 'Tentative croisée',
            'externalUrl' => 'https://example.test/crossed',
            'videoType' => VideoType::External->value,
            'usage' => 'gallery',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $media = $this->refresh($media);
        self::assertSame('Ancienne vidéo', $media->getTitle());
        self::assertSame('https://youtu.be/oldVideo123', $media->getExternalUrl());
        self::assertSame('https://img.youtube.com/vi/oldVideo123/hqdefault.jpg', $media->getThumbnailPath());

        $client->request('POST', sprintf('/admin/studio/place-media/%d/update', $link->getId()), [
            '_token' => $validToken,
            'title' => 'Vidéo corrigée',
            'caption' => 'Nouvelle légende',
            'externalUrl' => 'https://example.test/new-video',
            'videoType' => VideoType::Local->value,
            'usage' => 'main',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $media = $this->refresh($media);
        $link = $this->refresh($link);
        self::assertSame('Vidéo corrigée', $media->getTitle());
        self::assertSame('Nouvelle légende', $media->getCaption());
        self::assertSame('https://example.test/new-video', $media->getExternalUrl());
        self::assertSame(VideoType::External, $media->getVideoType());
        self::assertNull($media->getThumbnailPath());
        self::assertSame(MediaRole::Gallery, $link->getRole());
        self::assertNull($this->refresh($place)->getFeaturedImage());
    }
}
