<?php

namespace App\Tests\Functional;

use App\Entity\Destination;
use App\Enum\DestinationType;

final class AdminDestinationControllerTest extends FunctionalTestCase
{
    public function testAccessRulesForDestinationIndex(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/destinations');
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('GET', '/admin/destinations');
        self::assertResponseRedirects('/');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUnverifiedAdmin());
        $client->request('GET', '/admin/destinations');
        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenDestinationIndexAndNewForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/destinations');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/destinations/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nouvelle destination');
    }

    public function testCreateDestinationRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/destinations/new', [
            '_token' => 'bad-token',
            'name' => 'Destination CSRF invalide',
            'type' => DestinationType::Area->value,
        ]);

        self::assertResponseRedirects('/');
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => 'Destination CSRF invalide']));
    }

    public function testVerifiedAdminCanCreateMinimalDestinationWithCoordinates(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $name = 'Destination fonctionnelle minimale '.$this->uniqueToken('destination');
        $crawler = $client->request('GET', '/admin/destinations/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/destinations/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $name,
            'type' => DestinationType::Area->value,
            'latitude' => '42,70',
            'longitude' => '2.90',
            'description' => 'Destination creee par test fonctionnel.',
        ]);

        self::assertResponseRedirects('/admin/destinations');
        $destination = $this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Destination::class, $destination);
        self::assertSame(DestinationType::Area, $destination->getType());
        self::assertSame(42.70, $destination->getLatitude());
        self::assertSame(2.90, $destination->getLongitude());
    }

    public function testStructuredParentAndCoordinatesDoNotCreateIncorrectDestinationLinks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $parent = $this->createDestination();
        $name = 'Destination structurée '.$this->uniqueToken('destination');
        $crawler = $client->request('GET', '/admin/destinations/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/destinations/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $name,
            'type' => DestinationType::Area->value,
            'parent' => [$parent->getId()],
            'latitude' => ['42.70'],
            'longitude' => ['2.90'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->entityManager()->getRepository(Destination::class)->findOneBy(['name' => $name]));
        self::assertNotNull($this->entityManager()->find(Destination::class, $parent->getId()));
    }

    public function testEmptyDestinationNameIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/destinations/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/destinations/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => '',
            'type' => DestinationType::Area->value,
        ]);

        self::assertResponseIsSuccessful();
    }
}
