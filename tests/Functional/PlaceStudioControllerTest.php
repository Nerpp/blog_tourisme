<?php

namespace App\Tests\Functional;

use App\Enum\CategoryType;
use App\Enum\ContentStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;

final class PlaceStudioControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromPlaceEdit(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromPlaceEdit(): void
    {
        $client = static::createClient();
        $place = $this->createPlace();
        $client->loginUser($this->createUser());

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenPlaceEdit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $place = $this->createPlace();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testVerifiedAdminGetsNotFoundForMissingPlace(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin/studio/places/2147483647/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifiedAdminCanEditMinimalPlace(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $destination = $this->createDestination();
        $category = $this->createCategory(CategoryType::Place);
        $place = $this->createPlace();
        $token = $this->uniqueToken('place-edit');
        $name = 'Lieu fonctionnel modifié '.$token;
        $slug = 'lieu-fonctionnel-modifie-'.$token;
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/studio/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/studio/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => $name,
            'slug' => $slug,
            'destination' => (string) $destination->getId(),
            'category' => (string) $category->getId(),
            'status' => ContentStatus::Published->value,
            'action' => 'save',
            'shortDescription' => 'Description courte.',
            'description' => 'Description longue de test.',
            'address' => '1 rue du Test',
            'latitude' => '42.6986',
            'longitude' => '2.8956',
            'visitDurationMinutes' => '45',
            'difficulty' => PlaceDifficulty::Easy->value,
            'priceType' => PriceType::Free->value,
            'seoTitle' => 'SEO lieu test',
            'seoDescription' => 'SEO description lieu test.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/studio/places/%d/edit', $place->getId()));
        $place = $this->refresh($place);
        self::assertSame($name, $place->getName());
        self::assertSame($slug, $place->getSlug());
        self::assertSame($destination->getId(), $place->getDestination()?->getId());
        self::assertSame($category->getId(), $place->getCategory()?->getId());
        self::assertSame(ContentStatus::Published, $place->getStatus());
        self::assertSame(42.6986, $place->getLatitude());
        self::assertSame(2.8956, $place->getLongitude());
        self::assertSame(45, $place->getVisitDurationMinutes());
    }
}
