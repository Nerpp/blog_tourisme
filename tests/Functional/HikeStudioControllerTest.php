<?php

namespace App\Tests\Functional;

use App\Entity\Destination;
use App\Enum\HikeDraftStatus;

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
        self::assertNull($hike->getDestination());
        self::assertInstanceOf(Destination::class, $hike->getGeographicDestination());
        self::assertSame('66053', $hike->getGeographicDestination()->getCode());
        self::assertNotNull($hike->getFinishedAt());
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
            'type' => $point->getType()->value,
            'position' => (string) $point->getPosition(),
            'latitude' => '42.6986123',
            'longitude' => '2.8956456',
            'accuracy' => '4',
            'note' => 'Position relevee depuis le telephone.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#point-%d', $hike->getId(), $point->getId()));
        $point = $this->refresh($point);
        self::assertSame('Belvedere precis', $point->getTitle());
        self::assertSame(42.6986123, $point->getLatitude());
        self::assertSame(2.8956456, $point->getLongitude());
        self::assertSame(4.0, $point->getAccuracy());
    }
}
