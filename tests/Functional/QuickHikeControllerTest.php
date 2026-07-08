<?php

namespace App\Tests\Functional;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Enum\HikeDraftStatus;
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

    public function testVerifiedAdminIndexRedirectsToRequestedQuickHikeMode(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/quick-hike?mode=distance');

        self::assertResponseRedirects('/admin/quick?type=hike&mode=distance');
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
            'detectedCommuneName' => 'Commune partielle ignorée',
            'detectedCommuneCode' => '',
        ]);

        $hike = $this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(HikeDraft::class, $hike);
        self::assertNull($hike->getDestination());
        self::assertNull($hike->getGeographicDestination());
        self::assertNull($hike->getDetectedCommuneName());
        self::assertNull($hike->getDetectedCommuneCode());
        self::assertCount(0, $hike->getPoints());
        self::assertSame($admin->getId(), $hike->getCreatedBy()?->getId());
        self::assertResponseRedirects(sprintf('/admin/quick-hike/%d', $hike->getId()));

        $session = $client->getRequest()->getSession();
        self::assertSame($hike->getId(), $session->get('quick_hike_active_field_draft_id'));
        self::assertFalse($session->has('quick_hike_destination_id'));
        self::assertFalse($session->has('quick_city_visit_destination_id'));
        self::assertFalse($session->has('quick_hike_commune'));
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

    public function testQuickHikeCanStartRemoteDraftWithDefaultTitle(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/quick?type=hike&mode=distance');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/quick-hike/start', [
            '_token' => $this->csrfTokenForClient($client, 'quick_hike_start'),
            'title' => '   ',
            'creation_mode' => 'remote',
        ]);

        $hike = $this->entityManager()->getRepository(HikeDraft::class)->findOneBy(['createdBy' => $admin], ['id' => 'DESC']);
        self::assertInstanceOf(HikeDraft::class, $hike);
        self::assertStringStartsWith('Randonnée du ', (string) $hike->getTitle());
        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertFalse($client->getRequest()->getSession()->has('quick_hike_active_field_draft_id'));
    }

    public function testQuickHikeClearDestinationRequiresCsrfAndAcceptsValidToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/quick-hike/destination/clear', ['_token' => 'bad-token']);
        self::assertResponseRedirects('/admin/quick-hike');

        $client->request('POST', '/admin/quick-hike/destination/clear', [
            '_token' => $this->csrfTokenForClient($client, 'quick_hike_clear_destination'),
        ]);
        self::assertResponseRedirects('/admin/quick-hike');
    }

    public function testVerifiedAdminCanAbandonActiveFieldHike(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $client->request('GET', sprintf('/admin/quick-hike/%d', $hike->getId()));
        $session = $client->getRequest()->getSession();
        $session->set('quick_hike_active_field_draft_id', $hike->getId());
        $session->save();

        $client->request('POST', sprintf('/admin/quick-hike/%d/abandon', $hike->getId()), [
            '_token' => $this->csrfTokenForClient($client, 'quick_hike_abandon_'.$hike->getId()),
        ]);

        self::assertResponseRedirects('/admin/quick-hike');
        self::assertFalse($client->getRequest()->getSession()->has('quick_hike_active_field_draft_id'));
        self::assertInstanceOf(HikeDraft::class, $this->entityManager()->find(HikeDraft::class, $hike->getId()));
    }

    public function testQuickHikePointRejectsInvalidCoordinates(): void
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
                'latitude' => '120',
                'longitude' => '2.90',
                'type' => 'interest',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(HikePoint::class)->count(['hikeDraft' => $hike]));
        $stored = $this->refresh($hike);
        self::assertInstanceOf(HikeDraft::class, $stored);
        self::assertNull($stored->getGeographicDestination());
        self::assertNull($stored->getDetectedCommuneName());
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

    public function testQuickHikePointRejectsStructuredFieldsWithoutCreatingLocation(): void
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
                'latitude' => ['42.70'],
                'longitude' => ['2.90'],
                'accuracy' => ['8'],
                'type' => ['viewpoint'],
                'titlePoint' => ['Titre structuré'],
                'note' => ['Note structurée'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(HikePoint::class)->count(['hikeDraft' => $hike]));
        $stored = $this->refresh($hike);
        self::assertInstanceOf(HikeDraft::class, $stored);
        self::assertNull($stored->getGeographicDestination());
        self::assertNull($stored->getDetectedCommuneName());
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

    public function testQuickHikePointReturnsStructuredJsonSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $token = $this->csrfTokenForClient($client, 'quick_hike_point_'.$hike->getId());

        $client->request('POST', sprintf('/admin/quick-hike/%d/point', $hike->getId()), [
            'quick_hike_point' => [
                '_token' => $token,
                'latitude' => '42.7001',
                'longitude' => '2.9001',
                'type' => HikePointType::Start->value,
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'ok' => true,
            'message' => 'Point GPS enregistré.',
            'redirect' => sprintf('/admin/quick-hike/%d', $hike->getId()),
        ], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->entityManager()->getRepository(HikePoint::class)->count(['hikeDraft' => $hike]));
    }

    public function testQuickHikePointReturnsStructuredJsonError(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/quick-hike/%d/point', $hike->getId()), [
            'quick_hike_point' => [
                '_token' => $this->csrfTokenForClient($client, 'quick_hike_point_'.$hike->getId()),
                'type' => HikePointType::Start->value,
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([
            'ok' => false,
            'message' => 'La position GPS est obligatoire.',
        ], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
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

    public function testVerifiedAdminCanFinishFieldHikeAndContinueInStudio(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $startPoint = $this->createHikePoint($hike);
        $startPoint->setType(HikePointType::Start);
        $this->persistAndFlush($startPoint);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/quick-hike/%d', $hike->getId()));
        self::assertResponseIsSuccessful();
        $session = $client->getRequest()->getSession();
        $session->set('quick_hike_active_field_draft_id', $hike->getId());
        $session->save();

        $client->request('POST', sprintf('/admin/quick-hike/%d/finish', $hike->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/quick-hike/%d/finish', $hike->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        $stored = $this->refresh($hike);
        self::assertInstanceOf(HikeDraft::class, $stored);
        self::assertSame(HikeDraftStatus::Draft, $stored->getStatus());
        self::assertNotNull($stored->getFinishedAt());
        self::assertNull($stored->getDestination());
        self::assertNull($stored->getGeographicDestination());
        self::assertFalse($client->getRequest()->getSession()->has('quick_hike_active_field_draft_id'));
        self::assertFalse($client->getRequest()->getSession()->has('quick_hike_destination_id'));
        self::assertFalse($client->getRequest()->getSession()->has('quick_hike_commune'));
    }

    public function testQuickHikeCannotFinishWithoutStartPoint(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/quick-hike/%d/finish', $hike->getId()), [
            '_token' => $this->csrfTokenForClient($client, 'quick_hike_finish_'.$hike->getId()),
        ]);

        self::assertResponseRedirects(sprintf('/admin/quick-hike/%d', $hike->getId()));
        self::assertNull($this->refresh($hike)->getFinishedAt());
    }
}
