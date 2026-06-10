<?php

namespace App\Tests\Functional;

use App\Entity\Destination;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;

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
}
