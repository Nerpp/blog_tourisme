<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class MediaAssetFixtures extends Fixture implements DependentFixtureInterface
{
    public const COLLIOURE_STANDARD_REFERENCE = 'media.collioure-standard';
    public const COLLIOURE_PANORAMA_REFERENCE = 'media.collioure-panorama';
    public const FORT_SAINT_ELME_360_REFERENCE = 'media.fort-saint-elme-360';
    public const COTE_VERMEILLE_180_REFERENCE = 'media.cote-vermeille-180';
    public const PORT_COLLIOURE_WIDE_REFERENCE = 'media.port-collioure-wide';
    public const COLLIOURE_VIDEO_REFERENCE = 'media.collioure-video';

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);

        $mediaAssets = [
            self::COLLIOURE_STANDARD_REFERENCE => [
                'title' => 'Vue de Collioure',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'filePath' => '/uploads/demo/collioure-standard.jpg',
                'thumbnailPath' => '/uploads/demo/thumbs/collioure-standard.jpg',
                'altText' => 'Vue sur le village de Collioure',
                'caption' => 'Le clocher, les facades colorees et la baie de Collioure.',
                'mimeType' => 'image/jpeg',
                'width' => 1600,
                'height' => 900,
            ],
            self::COLLIOURE_PANORAMA_REFERENCE => [
                'title' => 'Panorama sur la baie de Collioure',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Panorama,
                'filePath' => '/uploads/demo/collioure-panorama.jpg',
                'thumbnailPath' => '/uploads/demo/thumbs/collioure-panorama.jpg',
                'altText' => 'Panorama de la baie de Collioure',
                'caption' => 'La baie de Collioure vue depuis les hauteurs de la cote Vermeille.',
                'mimeType' => 'image/jpeg',
                'width' => 2400,
                'height' => 900,
            ],
            self::FORT_SAINT_ELME_360_REFERENCE => [
                'title' => 'Visite immersive du Fort Saint-Elme',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Degree360,
                'projection' => 'equirectangular',
                'filePath' => '/uploads/demo/fort-saint-elme-360.jpg',
                'thumbnailPath' => '/uploads/demo/thumbs/fort-saint-elme-360.jpg',
                'altText' => 'Vue immersive depuis le Fort Saint-Elme',
                'caption' => 'Photo 360 de demonstration pour tester le lecteur immersif.',
                'mimeType' => 'image/jpeg',
                'width' => 4096,
                'height' => 2048,
                'metadata' => [
                    'viewer' => 'photo-sphere',
                    'initialYaw' => 0,
                    'initialPitch' => 0,
                    'hfov' => 360,
                    'vfov' => 180,
                ],
            ],
            self::COTE_VERMEILLE_180_REFERENCE => [
                'title' => 'Vue 180 degrés sur la côte Vermeille',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Degree180,
                'projection' => 'equirectangular',
                'filePath' => '/uploads/demo/cote-vermeille-180.jpg',
                'thumbnailPath' => '/uploads/demo/thumbs/cote-vermeille-180.jpg',
                'altText' => 'Vue 180 degrés sur les reliefs de la côte Vermeille',
                'caption' => 'Image panoramique partielle pour tester les formats immersifs.',
                'mimeType' => 'image/jpeg',
                'width' => 2200,
                'height' => 1100,
            ],
            self::PORT_COLLIOURE_WIDE_REFERENCE => [
                'title' => 'Grand angle sur le port de Collioure',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::WideAngle,
                'filePath' => '/uploads/demo/port-collioure-wide.jpg',
                'thumbnailPath' => '/uploads/demo/thumbs/port-collioure-wide.jpg',
                'altText' => 'Vue grand angle du port de Collioure',
                'caption' => 'Le port de Collioure photographie au grand angle.',
                'mimeType' => 'image/jpeg',
                'width' => 1800,
                'height' => 1000,
            ],
            self::COLLIOURE_VIDEO_REFERENCE => [
                'title' => 'Découverte de Collioure en vidéo',
                'mediaType' => MediaType::Video,
                'videoType' => VideoType::Youtube,
                'externalUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'thumbnailPath' => '/uploads/demo/thumbs/video-collioure.jpg',
                'altText' => 'Miniature de la vidéo de découverte de Collioure',
                'caption' => 'Video externe de demonstration pour tester les contenus YouTube.',
                'durationSeconds' => 212,
            ],
        ];

        foreach ($mediaAssets as $reference => $data) {
            $mediaAsset = (new MediaAsset())
                ->setUploadedBy($admin)
                ->setTitle($data['title'])
                ->setMediaType($data['mediaType'])
                ->setImageType($data['imageType'] ?? null)
                ->setVideoType($data['videoType'] ?? null)
                ->setFilePath($data['filePath'] ?? null)
                ->setThumbnailPath($data['thumbnailPath'])
                ->setExternalUrl($data['externalUrl'] ?? null)
                ->setAltText($data['altText'])
                ->setCaption($data['caption'])
                ->setMimeType($data['mimeType'] ?? null)
                ->setWidth($data['width'] ?? null)
                ->setHeight($data['height'] ?? null)
                ->setDurationSeconds($data['durationSeconds'] ?? null)
                ->setProjection($data['projection'] ?? null)
                ->setMetadata($data['metadata'] ?? null);

            $manager->persist($mediaAsset);
            $this->addReference($reference, $mediaAsset);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }

    private function getUser(string $reference): User
    {
        return $this->getReference($reference, User::class);
    }
}
