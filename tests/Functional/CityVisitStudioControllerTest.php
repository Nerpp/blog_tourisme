<?php

namespace App\Tests\Functional;

use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
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
