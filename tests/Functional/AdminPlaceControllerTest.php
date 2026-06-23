<?php

namespace App\Tests\Functional;

use App\Entity\Place;
use App\Enum\ContentStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;

final class AdminPlaceControllerTest extends FunctionalTestCase
{
    public function testAccessRulesForPlaceIndex(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/places');
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('GET', '/admin/places');
        self::assertResponseRedirects('/');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUnverifiedAdmin());
        $client->request('GET', '/admin/places');
        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenPlaceIndexAndNewForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/places');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/places/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nouveau repérage');
    }

    public function testCreatePlaceRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/places/new', [
            '_token' => 'bad-token',
            'name' => 'Repérage CSRF invalide',
        ]);

        self::assertResponseRedirects('/');
        self::assertNull($this->entityManager()->getRepository(Place::class)->findOneBy(['name' => 'Repérage CSRF invalide']));
    }

    public function testVerifiedAdminCanCreateMinimalPrivatePlaceWithoutDestination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $category = $this->createCategory();
        $name = 'Repérage fonctionnel minimal '.$this->uniqueToken('place');
        $crawler = $client->request('GET', '/admin/places/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/places/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $name,
            'category' => (string) $category->getId(),
            'difficulty' => PlaceDifficulty::Easy->value,
            'priceType' => PriceType::Free->value,
            'latitude' => '42.71',
            'longitude' => '2.91',
        ]);

        self::assertResponseRedirects('/admin/places');
        $place = $this->entityManager()->getRepository(Place::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Place::class, $place);
        self::assertNull($place->getDestination());
        self::assertSame(ContentStatus::Draft, $place->getStatus());
        self::assertSame(PlaceDifficulty::Easy, $place->getDifficulty());
        self::assertSame(PriceType::Free, $place->getPriceType());
    }

    public function testStructuredIdentifiersAndNumbersDoNotCreateIncorrectPlaceLinks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $destination = $this->createDestination();
        $category = $this->createCategory();
        $name = 'Repérage structuré '.$this->uniqueToken('place');
        $crawler = $client->request('GET', '/admin/places/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/places/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $name,
            'destination' => [$destination->getId()],
            'category' => [$category->getId()],
            'latitude' => ['42.71'],
            'longitude' => ['2.91'],
            'visitDurationMinutes' => ['45'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->entityManager()->getRepository(Place::class)->findOneBy(['name' => $name]));
    }

    public function testEmptyPlaceNameIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/places/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/places/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => '',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testDeletePlaceRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $place = $this->createPlace();

        $client->request('POST', sprintf('/admin/places/%d/delete', $place->getId()), ['_token' => 'bad-token']);

        self::assertResponseRedirects('/');
        self::assertNotNull($this->entityManager()->find(Place::class, $place->getId()));
    }
}
