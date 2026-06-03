<?php

namespace App\Tests\Functional;

use App\Entity\Destination;
use App\Enum\CityVisitDraftStatus;

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

        self::assertResponseRedirects(sprintf('/admin/studio/city-visits/%d/edit', $cityVisit->getId()));
        $cityVisit = $this->refresh($cityVisit);
        self::assertSame($title, $cityVisit->getTitle());
        self::assertSame(CityVisitDraftStatus::Finished, $cityVisit->getStatus());
        self::assertNull($cityVisit->getDestination());
        self::assertInstanceOf(Destination::class, $cityVisit->getGeographicDestination());
        self::assertSame('66136', $cityVisit->getGeographicDestination()->getCode());
        self::assertNotNull($cityVisit->getFinishedAt());
    }
}
