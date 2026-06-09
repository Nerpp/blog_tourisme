<?php

namespace App\Tests\Functional;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Enum\HikePointType;

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

    public function testQuickHikePointRejectsMissingCoordinates(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-hike/%d', $hike->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Enregistrer le point de départ', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Ajouter le point d’intérêt', (string) $client->getResponse()->getContent());

        $client->request('POST', sprintf('/admin/quick-hike/%d/point', $hike->getId()), [
            'quick_hike_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_hike_point[_token]"]'),
                'type' => 'start',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-alert', 'La position GPS est obligatoire.');
        self::assertSame(0, $this->entityManager()->getRepository(HikePoint::class)->count(['hikeDraft' => $hike]));
    }

    public function testQuickHikePointCreatesStartPointWithCoordinates(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-hike/%d', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/quick-hike/%d/point', $hike->getId()), [
            'quick_hike_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_hike_point[_token]"]'),
                'latitude' => '42.7001234',
                'longitude' => '2.9005678',
                'accuracy' => '8',
                'type' => 'interest',
                'titlePoint' => 'Départ terrain',
                'note' => 'Départ relevé automatiquement.',
            ],
        ]);

        self::assertResponseRedirects(sprintf('/admin/quick-hike/%d', $hike->getId()));

        $points = $this->entityManager()->getRepository(HikePoint::class)->findBy(['hikeDraft' => $hike], ['position' => 'ASC']);
        self::assertCount(1, $points);
        self::assertSame(HikePointType::Start, $points[0]->getType());
        self::assertSame('Départ terrain', $points[0]->getTitle());
        self::assertSame('Départ relevé automatiquement.', $points[0]->getNote());
        self::assertEqualsWithDelta(42.7001234, $points[0]->getLatitude(), 0.0000001);
        self::assertEqualsWithDelta(2.9005678, $points[0]->getLongitude(), 0.0000001);
        self::assertEqualsWithDelta(8.0, $points[0]->getAccuracy(), 0.0000001);
        self::assertSame(1, $points[0]->getPosition());
    }

    public function testQuickHikePointCreatesInterestPointAfterStart(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $startPoint = $this->createHikePoint($hike, 42.7000, 2.9000, 1);
        $startPoint->setType(HikePointType::Start);
        $this->persistAndFlush($startPoint);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-hike/%d', $hike->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ajouter le point d’intérêt', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Enregistrer le point de départ', (string) $client->getResponse()->getContent());

        $client->request('POST', sprintf('/admin/quick-hike/%d/point', $hike->getId()), [
            'quick_hike_point' => [
                '_token' => $this->inputValue($crawler, 'input[name="quick_hike_point[_token]"]'),
                'latitude' => '42.7011111',
                'longitude' => '2.9012222',
                'accuracy' => '12',
                'type' => 'viewpoint',
                'titlePoint' => 'Belvédère',
                'note' => 'Vue dégagée.',
            ],
        ]);

        self::assertResponseRedirects(sprintf('/admin/quick-hike/%d', $hike->getId()));

        $points = $this->entityManager()->getRepository(HikePoint::class)->findBy(['hikeDraft' => $hike], ['position' => 'ASC']);
        self::assertCount(2, $points);
        self::assertSame(HikePointType::Start, $points[0]->getType());
        self::assertSame(HikePointType::Viewpoint, $points[1]->getType());
        self::assertSame('Belvédère', $points[1]->getTitle());
        self::assertSame('Vue dégagée.', $points[1]->getNote());
        self::assertEqualsWithDelta(42.7011111, $points[1]->getLatitude(), 0.0000001);
        self::assertEqualsWithDelta(2.9012222, $points[1]->getLongitude(), 0.0000001);
        self::assertEqualsWithDelta(12.0, $points[1]->getAccuracy(), 0.0000001);
        self::assertSame(2, $points[1]->getPosition());
    }
}
