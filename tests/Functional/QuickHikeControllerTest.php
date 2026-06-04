<?php

namespace App\Tests\Functional;

use App\Entity\HikeDraft;

final class QuickHikeControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromQuickHike(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/quick-hike');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromQuickHike(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/quick-hike');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanStartFieldHikeWithoutDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $title = 'Terrain randonnée fonctionnel '.$this->uniqueToken('hike');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/quick?type=hike&mode=terrain');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/quick-hike/start', [
            '_token' => $this->tokenFromFormAction($crawler, '/admin/quick-hike/start'),
            'title' => $title,
            'creation_mode' => 'field',
        ]);

        $hike = $this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(HikeDraft::class, $hike);
        self::assertNull($hike->getDestination());
        self::assertSame($admin->getId(), $hike->getCreatedBy()?->getId());
        self::assertResponseRedirects(sprintf('/admin/quick-hike/%d', $hike->getId()));
    }

    public function testQuickHikeStartRequiresCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/quick-hike/start', [
            '_token' => 'bad-token',
            'title' => 'Quick hike csrf invalide',
        ]);

        self::assertResponseRedirects('/admin/quick-hike');
        self::assertNull($this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['title' => 'Quick hike csrf invalide']));
    }

    public function testQuickHikePointRejectsInvalidCoordinates(): void
    {
        $client = static::createClient();
        $hike = $this->createHikeDraft($this->createVerifiedAdmin());
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', sprintf('/admin/quick-hike/%d', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/quick-hike/%d/point', $hike->getId()), [
            'quick_hike_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_hike_point[_token]"]'),
                'latitude' => '120',
                'longitude' => '2.90',
                'type' => 'interest',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
