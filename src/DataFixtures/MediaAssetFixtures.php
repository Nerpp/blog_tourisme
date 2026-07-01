<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\Media\MediaVariantService;
use App\Service\Media\PublicMediaMasterCleanupService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use GdImage;

final class MediaAssetFixtures extends Fixture implements DependentFixtureInterface
{
    public const COLLIOURE_STANDARD_REFERENCE = 'media.collioure-standard';
    public const COLLIOURE_PANORAMA_REFERENCE = 'media.collioure-panorama';
    public const FORT_SAINT_ELME_360_REFERENCE = 'media.fort-saint-elme-360';
    public const COTE_VERMEILLE_180_REFERENCE = 'media.cote-vermeille-180';
    public const PORT_COLLIOURE_WIDE_REFERENCE = 'media.port-collioure-wide';
    public const COLLIOURE_VIDEO_REFERENCE = 'media.collioure-video';
    public const MONTAGNE_REFERENCE = 'media.fixture-montagne';
    public const MER_REFERENCE = 'media.fixture-mer';
    public const VILLAGE_REFERENCE = 'media.fixture-village';
    public const RANDONNEE_REFERENCE = 'media.fixture-randonnee';
    public const RUELLE_REFERENCE = 'media.fixture-ruelle';
    public const CHATEAU_REFERENCE = 'media.fixture-chateau';
    public const LAC_REFERENCE = 'media.fixture-lac';
    public const FORET_REFERENCE = 'media.fixture-foret';
    public const LIGHTHOUSE_COVER_REFERENCE = self::COTE_VERMEILLE_180_REFERENCE;

    public function __construct(
        private readonly string $projectDir,
        private readonly MediaVariantService $mediaVariantService,
        private readonly PublicMediaMasterCleanupService $publicMediaMasterCleanupService,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);

        $mediaAssets = [
            self::COLLIOURE_STANDARD_REFERENCE => [
                'title' => 'Vue de Collioure',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_collioure_standard',
                'palette' => ['sky', 'sea', 'village'],
                'altText' => 'Vue sur le village de Collioure',
                'caption' => 'Le clocher, les facades colorees et la baie de Collioure.',
                'width' => 1600,
                'height' => 900,
            ],
            self::COLLIOURE_PANORAMA_REFERENCE => [
                'title' => 'Panorama sur la baie de Collioure',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Panorama,
                'fixture' => 'fixture_media_collioure_panorama',
                'palette' => ['sunset', 'sea', 'mountain'],
                'altText' => 'Panorama de la baie de Collioure',
                'caption' => 'La baie de Collioure vue depuis les hauteurs de la cote Vermeille.',
                'width' => 2400,
                'height' => 900,
            ],
            self::FORT_SAINT_ELME_360_REFERENCE => [
                'title' => 'Visite immersive du Fort Saint-Elme',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Degree360,
                'projection' => 'equirectangular',
                'fixture' => 'fixture_media_fort_saint_elme_360',
                'palette' => ['stone', 'sea', 'mountain'],
                'altText' => 'Vue immersive depuis le Fort Saint-Elme',
                'caption' => 'Photo 360 de demonstration pour tester le lecteur immersif.',
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
                'fixture' => 'fixture_media_cote_vermeille_180',
                'palette' => ['sea', 'vineyard', 'mountain'],
                'altText' => 'Vue 180 degrés sur les reliefs de la côte Vermeille',
                'caption' => 'Image panoramique partielle pour tester les formats immersifs.',
                'width' => 2200,
                'height' => 1100,
            ],
            self::PORT_COLLIOURE_WIDE_REFERENCE => [
                'title' => 'Grand angle sur le port de Collioure',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::WideAngle,
                'fixture' => 'fixture_media_port_collioure_wide',
                'palette' => ['sky', 'harbor', 'village'],
                'altText' => 'Vue grand angle du port de Collioure',
                'caption' => 'Le port de Collioure photographie au grand angle.',
                'width' => 1800,
                'height' => 1000,
            ],
            self::COLLIOURE_VIDEO_REFERENCE => [
                'title' => 'Découverte de Collioure en vidéo',
                'mediaType' => MediaType::Video,
                'videoType' => VideoType::Youtube,
                'externalUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'fixture' => 'fixture_media_video_collioure',
                'palette' => ['sky', 'sea', 'village'],
                'altText' => 'Miniature de la vidéo de découverte de Collioure',
                'caption' => 'Video externe de demonstration pour tester les contenus YouTube.',
                'durationSeconds' => 212,
                'width' => 1280,
                'height' => 720,
            ],
            self::MONTAGNE_REFERENCE => [
                'title' => 'Reliefs du Canigou',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_montagne',
                'palette' => ['sky', 'mountain', 'forest'],
                'altText' => 'Reliefs de montagne au-dessus du Conflent',
                'caption' => 'Image locale de test pour les randonnées de montagne.',
                'width' => 1600,
                'height' => 900,
            ],
            self::MER_REFERENCE => [
                'title' => 'Côte rocheuse méditerranéenne',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::WideAngle,
                'fixture' => 'fixture_media_mer',
                'palette' => ['sky', 'sea', 'stone'],
                'altText' => 'Mer bleue et côte rocheuse',
                'caption' => 'Image locale de test pour les contenus de bord de mer.',
                'width' => 1800,
                'height' => 1000,
            ],
            self::VILLAGE_REFERENCE => [
                'title' => 'Village catalan',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_village',
                'palette' => ['sky', 'village', 'vineyard'],
                'altText' => 'Ruelles et toits d un village catalan',
                'caption' => 'Image locale de test pour les pages village.',
                'width' => 1600,
                'height' => 900,
            ],
            self::RANDONNEE_REFERENCE => [
                'title' => 'Sentier de randonnée',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_randonnee',
                'palette' => ['sky', 'trail', 'forest'],
                'altText' => 'Sentier balisé entre pins et collines',
                'caption' => 'Image locale de test pour les cartes GPS et galeries de randonnée.',
                'width' => 1600,
                'height' => 900,
            ],
            self::RUELLE_REFERENCE => [
                'title' => 'Ruelle colorée',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_ruelle',
                'palette' => ['stone', 'village', 'sunset'],
                'altText' => 'Ruelle colorée dans un centre ancien',
                'caption' => 'Image locale de test pour les visites de ville.',
                'width' => 1600,
                'height' => 900,
            ],
            self::CHATEAU_REFERENCE => [
                'title' => 'Château et remparts',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_chateau',
                'palette' => ['sky', 'stone', 'village'],
                'altText' => 'Remparts et silhouette de château',
                'caption' => 'Image locale de test pour les contenus patrimoine.',
                'width' => 1600,
                'height' => 900,
            ],
            self::LAC_REFERENCE => [
                'title' => 'Lac de montagne',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Panorama,
                'fixture' => 'fixture_media_lac',
                'palette' => ['sky', 'lake', 'forest'],
                'altText' => 'Lac calme entoure de pins',
                'caption' => 'Image locale de test pour les lieux naturels.',
                'width' => 1800,
                'height' => 900,
            ],
            self::FORET_REFERENCE => [
                'title' => 'Forêt méditerranéenne',
                'mediaType' => MediaType::Image,
                'imageType' => ImageType::Standard,
                'fixture' => 'fixture_media_foret',
                'palette' => ['forest', 'trail', 'mountain'],
                'altText' => 'Sous-bois et chemin de forêt',
                'caption' => 'Image locale de test pour les galeries nature.',
                'width' => 1600,
                'height' => 900,
            ],
        ];

        foreach ($mediaAssets as $reference => $data) {
            $isStandardImage = false;
            if ($data['mediaType'] === MediaType::Image) {
                $isStandardImage = $data['imageType'] === ImageType::Standard;
            }
            $paths = $this->generateImage(
                $data['fixture'],
                $data['width'],
                $data['height'],
                $data['palette'],
                generateStandaloneThumbnail: !$isStandardImage,
            );

            $mediaAsset = (new MediaAsset())
                ->setUploadedBy($admin)
                ->setTitle($data['title'])
                ->setMediaType($data['mediaType'])
                ->setImageType($data['imageType'] ?? null)
                ->setVideoType($data['videoType'] ?? null)
                ->setFilePath($data['mediaType'] === MediaType::Image ? $paths['path'] : null)
                ->setThumbnailPath($paths['thumb'])
                ->setExternalUrl($data['externalUrl'] ?? null)
                ->setAltText($data['altText'])
                ->setCaption($data['caption'])
                ->setMimeType($paths['mime'])
                ->setFileSize($paths['size'])
                ->setWidth($data['width'])
                ->setHeight($data['height'])
                ->setDurationSeconds($data['durationSeconds'] ?? null)
                ->setProjection($data['projection'] ?? null)
                ->setMetadata($data['metadata'] ?? null);

            if ($isStandardImage && $mediaAsset->getFilePath() !== null) {
                $variantResult = $this->mediaVariantService->generateForMedia($mediaAsset, force: true);
                if ($variantResult['generated']) {
                    $this->publicMediaMasterCleanupService->cleanupIfSafe($mediaAsset);
                }
            }

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

    /**
     * @param list<string> $palette
     * @return array{path: string|null, thumb: string|null, mime: string|null, size: int|null}
     */
    private function generateImage(
        string $basename,
        int $width,
        int $height,
        array $palette,
        bool $generateStandaloneThumbnail = true,
    ): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return ['path' => null, 'thumb' => null, 'mime' => null, 'size' => null];
        }

        $supportsWebp = function_exists('imagewebp');
        $extension = $supportsWebp ? 'webp' : 'jpg';
        $mime = $supportsWebp ? 'image/webp' : 'image/jpeg';
        $directory = $this->projectDir.'/public/uploads/media';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $relativePath = sprintf('/uploads/media/%s.%s', $basename, $extension);
        $relativeThumbPath = sprintf('/uploads/media/%s_thumb.%s', $basename, $extension);
        $absolutePath = $this->projectDir.'/public'.$relativePath;
        $absoluteThumbPath = $this->projectDir.'/public'.$relativeThumbPath;

        $this->drawImage($absolutePath, $width, $height, $palette, $supportsWebp);
        if ($generateStandaloneThumbnail) {
            $this->drawImage($absoluteThumbPath, 480, 270, $palette, $supportsWebp);
        } elseif (is_file($absoluteThumbPath)) {
            @unlink($absoluteThumbPath);
        }

        return [
            'path' => $relativePath,
            'thumb' => $generateStandaloneThumbnail ? $relativeThumbPath : null,
            'mime' => $mime,
            'size' => is_file($absolutePath) ? filesize($absolutePath) ?: null : null,
        ];
    }

    /**
     * @param list<string> $palette
     */
    private function drawImage(string $path, int $width, int $height, array $palette, bool $webp): void
    {
        if ($width < 1 || $height < 1) {
            return;
        }

        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            return;
        }

        $sky = $this->allocateColor($image, $palette[0] ?? 'sky');
        $middle = $this->allocateColor($image, $palette[1] ?? 'sea');
        $foreground = $this->allocateColor($image, $palette[2] ?? 'village');
        if ($sky === null || $middle === null || $foreground === null) {
            imagedestroy($image);

            return;
        }

        imagefilledrectangle($image, 0, 0, $width, (int) ($height * 0.55), $sky);
        imagefilledrectangle($image, 0, (int) ($height * 0.55), $width, $height, $middle);

        $points = [
            0, (int) ($height * 0.62),
            (int) ($width * 0.22), (int) ($height * 0.34),
            (int) ($width * 0.48), (int) ($height * 0.62),
            (int) ($width * 0.72), (int) ($height * 0.28),
            $width, (int) ($height * 0.62),
            $width, $height,
            0, $height,
        ];
        imagefilledpolygon($image, $points, $foreground);

        $accent = imagecolorallocate($image, 255, 237, 179);
        if (is_int($accent)) {
            imagefilledellipse($image, (int) ($width * 0.82), (int) ($height * 0.18), (int) ($width * 0.12), (int) ($width * 0.12), $accent);
        }

        $webp ? imagewebp($image, $path, 86) : imagejpeg($image, $path, 88);
        imagedestroy($image);
    }

    private function allocateColor(GdImage $image, string $name): ?int
    {
        $rgb = [
            'sky' => [143, 191, 214],
            'sea' => [36, 121, 151],
            'village' => [199, 111, 74],
            'sunset' => [238, 159, 92],
            'mountain' => [90, 112, 91],
            'stone' => [150, 137, 116],
            'vineyard' => [113, 134, 74],
            'harbor' => [51, 145, 168],
            'forest' => [58, 105, 72],
            'trail' => [178, 143, 92],
            'lake' => [67, 142, 177],
        ][$name] ?? [120, 120, 120];

        $color = imagecolorallocate($image, ...$rgb);

        return is_int($color) ? $color : null;
    }
}
