<?php

namespace App\Tests\Unit;

use App\Entity\Destination;
use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleDestination;
use App\Entity\ArticleHike;
use App\Entity\ArticlePlace;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\CityVisitDraftStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaSeoTextService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MediaSeoTextServiceTest extends TestCase
{
    public function testPublicTitlePrefersHumanTitle(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/IMG_1234.jpg')
            ->setTitle('Vue depuis la crête');

        self::assertSame('Vue depuis la crête', $this->service()->publicTitle($media, 'Randonnée du test'));
    }

    public function testPublicAltFallsBackToContextWhenAltLooksTechnical(): void
    {
        $department = (new Destination())
            ->setName('Pyrenees-Orientales')
            ->setSlug('pyrenees-orientales')
            ->setType(DestinationType::Department);
        $city = (new Destination())
            ->setName('Collioure')
            ->setSlug('collioure')
            ->setType(DestinationType::City)
            ->setParent($department);
        $place = (new Place())
            ->setName('Fort Saint-Elme')
            ->setSlug('fort-saint-elme')
            ->setDestination($city);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setFilePath('/uploads/media/IMG_1234.jpg')
            ->setAltText('IMG_1234.jpg');

        self::assertSame(
            'Panorama de Fort Saint-Elme à Collioure dans les Pyrenees-Orientales',
            $this->service()->publicAlt($media, $place),
        );
    }

    public function testFilenameBaseCombinesContextDestinationAndMediaType(): void
    {
        self::assertSame(
            'fort-saint-elme-collioure-panorama',
            $this->service()->filenameBaseForContext(
                (new Place())
                    ->setName('Fort Saint-Elme')
                    ->setSlug('fort-saint-elme')
                    ->setDestination((new Destination())
                        ->setName('Collioure')
                        ->setSlug('collioure')
                        ->setType(DestinationType::City)),
                MediaType::Image,
                ImageType::WideAngle,
            ),
        );
    }

    public function testArticleContextCanResolveDestinationFromRelatedContentInPriorityOrder(): void
    {
        $department = (new Destination())->setName('Aude')->setSlug('aude')->setType(DestinationType::Department);
        $hikeDestination = (new Destination())->setName('Prades')->setSlug('prades')->setType(DestinationType::City)->setParent($department);
        $cityDestination = (new Destination())->setName('Perpignan')->setSlug('perpignan')->setType(DestinationType::City);
        $placeDestination = (new Destination())->setName('Collioure')->setSlug('collioure')->setType(DestinationType::City);
        $directDestination = (new Destination())->setName('Narbonne')->setSlug('narbonne')->setType(DestinationType::City);

        $article = (new Article())->setTitle('Itinéraire d été');
        $article->getHikeLinks()->add((new ArticleHike())->setArticle($article)->setHikeDraft((new HikeDraft())->setDestination($hikeDestination)));
        $article->getCityVisitLinks()->add((new ArticleCityVisit())->setArticle($article)->setCityVisitDraft((new CityVisitDraft())->setDestination($cityDestination)));
        $article->getPlaceLinks()->add((new ArticlePlace())->setArticle($article)->setPlace((new Place())->setDestination($placeDestination)));
        $article->getDestinationLinks()->add((new ArticleDestination())->setArticle($article)->setDestination($directDestination));

        self::assertSame(
            'Vue de Itinéraire d été à Prades dans les Aude',
            $this->service()->altTextForContext($article),
        );
    }

    public function testDetectedLocationIsUsedWhenContextHasNoDestination(): void
    {
        $hike = (new HikeDraft())
            ->setTitle('Boucle des crêtes')
            ->setStatus(HikeDraftStatus::Finished)
            ->setDetectedCommuneName('Céret')
            ->setDetectedDepartmentName('Pyrénées-Orientales');
        $visit = (new CityVisitDraft())
            ->setTitle('Centre ancien')
            ->setStatus(CityVisitDraftStatus::Finished)
            ->setDetectedCommuneName('Perpignan')
            ->setDetectedDepartmentName('Pyrénées-Orientales');

        self::assertSame('Photo de Boucle des crêtes à Céret', $this->service()->titleForContext($hike));
        self::assertSame('Vue de Centre ancien à Perpignan dans les Pyrénées-Orientales', $this->service()->altTextForContext($visit));
    }

    public function testVideoAndSpecialImagePrefixesAreUsedForTitlesAltAndFilenames(): void
    {
        $service = $this->service();

        self::assertSame('Vidéo de Collioure', $service->titleForContext('Collioure', MediaType::Video));
        self::assertSame('Vidéo de Collioure', $service->altTextForContext('Collioure', MediaType::Video));
        self::assertSame('collioure-video', $service->filenameBaseForContext('Collioure', MediaType::Video));

        self::assertSame('Vue 360° de Belvédère', $service->titleForContext('Belvédère', MediaType::Image, ImageType::Degree360));
        self::assertSame('Panorama 360° de Belvédère', $service->altTextForContext('Belvédère', MediaType::Image, ImageType::Degree360));
        self::assertSame('belvedere-vue-360', $service->filenameBaseForContext('Belvédère', MediaType::Image, ImageType::Degree360));
        self::assertSame('belvedere-panorama-180', $service->filenameBaseForContext('Belvédère', MediaType::Image, ImageType::Degree180));
    }

    public function testPublicTitleFallsBackFromTechnicalTitleToCaptionThenContext(): void
    {
        $service = $this->service();
        $mediaWithCaption = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/IMG_1234.jpg')
            ->setTitle('IMG_1234.jpg')
            ->setCaption('Vue sur le port');
        $technicalMedia = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/IMG_1234.jpg')
            ->setTitle('IMG_1234.jpg')
            ->setCaption('optimized original');

        self::assertSame('Vue sur le port', $service->publicTitle($mediaWithCaption, 'Collioure'));
        self::assertSame('Photo de Collioure', $service->publicTitle($technicalMedia, 'Collioure'));
        self::assertSame('Photo de Fallback title', $service->titleForContext(null, fallbackTitle: 'Fallback title'));
        self::assertSame('Photo de ce lieu', $service->titleForContext(null));
    }

    public function testTechnicalTextDetectionCatchesCameraFilenamesAndOptimizedNames(): void
    {
        $media = (new MediaAsset())
            ->setFilePath('/uploads/media/photo-original-abc123.jpg')
            ->setThumbnailPath('/uploads/media/thumbnail.jpg');
        $service = $this->service();

        self::assertTrue($service->isTechnicalText('IMG_1234.jpg', $media));
        self::assertTrue($service->isTechnicalText('photo-original-abc123', $media));
        self::assertTrue($service->isTechnicalText('PXL_20250101120000'));
        self::assertTrue($service->isTechnicalText('hash_123456789012abcdef'));
        self::assertTrue($service->isTechnicalText('very-long-technical-a1b2c3d4e5f6-original-name'));
        self::assertFalse($service->isTechnicalText('Coucher de soleil', $media));
    }

    public function testDynamicContextIgnoresNonStringTextMetadata(): void
    {
        $context = new class {
            /** @return array<string, string> */
            public function getTitle(): array
            {
                return ['title' => 'Titre structuré'];
            }

            public function getDetectedCommuneName(): object
            {
                return new \stdClass();
            }

            public function getDetectedDepartmentName(): bool
            {
                return true;
            }
        };

        self::assertSame(
            'Photo de Titre de repli',
            $this->service()->titleForContext($context, fallbackTitle: ' Titre de repli '),
        );
        self::assertSame(
            'Vue de Titre de repli',
            $this->service()->altTextForContext($context, fallbackTitle: ' Titre de repli '),
        );
    }

    private function service(): MediaSeoTextService
    {
        return new MediaSeoTextService(new AsciiSlugger('fr'));
    }
}
