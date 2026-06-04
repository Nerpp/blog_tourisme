<?php

namespace App\Tests\Functional;

use App\Entity\Destination;
use App\Enum\DestinationType;

final class QuickDestinationControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromQuickDestinationCreate(): void
    {
        $client = static::createClient();

        $client->request('POST', '/admin/studio/destinations/quick-create');

        self::assertResponseRedirects('/login');
    }

    public function testQuickDestinationCreateRequiresCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => 'bad-token',
            'name' => 'Destination rapide csrf invalide',
            'type' => DestinationType::Area->value,
        ]);

        self::assertResponseRedirects('/admin');
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide csrf invalide']));
    }

    public function testVerifiedAdminCanCreateQuickDestination(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hike->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/studio/destinations/quick-create', [
            '_token' => $this->tokenFromFormAction($crawler, '/admin/studio/destinations/quick-create'),
            'name' => 'Destination rapide fonctionnelle',
            'type' => DestinationType::Area->value,
            'returnUrl' => '/admin/studio',
        ]);

        self::assertResponseRedirects('/admin/studio');
        $destination = $this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination rapide fonctionnelle']);
        self::assertInstanceOf(Destination::class, $destination);
        self::assertSame(DestinationType::Area, $destination->getType());
    }
}
