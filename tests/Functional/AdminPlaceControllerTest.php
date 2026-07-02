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

    public function testVerifiedAdminCanEditPlaceWhileArchivedStatusRemainsArchived(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $destination = $this->createDestination();
        $category = $this->createCategory();
        $place = $this->createPlace();
        $place->setStatus(ContentStatus::Archived);
        $this->persistAndFlush($place);
        $crawler = $client->request('GET', sprintf('/admin/places/%d/edit', $place->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/places/%d/edit', $place->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => 'Repérage administratif modifié',
            'destination' => (string) $destination->getId(),
            'category' => (string) $category->getId(),
            'shortDescription' => '  Description courte.  ',
            'address' => '  1 rue des Tests  ',
            'latitude' => '42,72',
            'longitude' => '2.92',
            'visitDurationMinutes' => '0',
            'difficulty' => PlaceDifficulty::Hard->value,
            'priceType' => PriceType::Paid->value,
        ]);

        self::assertResponseRedirects('/admin/places');
        $place = $this->refresh($place);
        self::assertSame('Repérage administratif modifié', $place->getName());
        self::assertSame($destination->getId(), $place->getDestination()?->getId());
        self::assertSame($category->getId(), $place->getCategory()?->getId());
        self::assertSame(ContentStatus::Archived, $place->getStatus());
        self::assertSame(0, $place->getVisitDurationMinutes());
        self::assertSame(PlaceDifficulty::Hard, $place->getDifficulty());
        self::assertSame(PriceType::Paid, $place->getPriceType());
        self::assertNull($place->getPublishedAt());
    }

    public function testPlaceEditRequiresValidCsrfAndKeepsPersistedValues(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $place = $this->createPlace();
        $originalName = $place->getName();

        $client->request('POST', sprintf('/admin/places/%d/edit', $place->getId()), [
            '_token' => 'bad-token',
            'name' => 'Modification non autorisee',
        ]);

        self::assertResponseRedirects('/');
        self::assertSame($originalName, $this->refresh($place)->getName());
    }

    public function testPlaceCreationUsesUniqueSlugAndNormalizesInvalidOptionalValues(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $name = 'Repérage slug partagé '.$this->uniqueToken('place');

        foreach ([$name, $name] as $currentName) {
            $crawler = $client->request('GET', '/admin/places/new');
            self::assertResponseIsSuccessful();
            $client->request('POST', '/admin/places/new', [
                '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
                'name' => $currentName,
                'destination' => '-1',
                'category' => '999999999999999999999999999999',
                'visitDurationMinutes' => '-5',
                'difficulty' => 'unexpected',
                'priceType' => 'unexpected',
                'latitude' => 'not-a-number',
                'longitude' => '1e9999',
            ]);
            self::assertResponseRedirects('/admin/places');
        }

        $places = $this->entityManager()->getRepository(Place::class)->findBy(['name' => $name], ['id' => 'ASC']);
        self::assertCount(2, $places);
        self::assertNotSame($places[0]->getSlug(), $places[1]->getSlug());
        self::assertStringEndsWith('-2', (string) $places[1]->getSlug());
        self::assertNull($places[1]->getDestination());
        self::assertNull($places[1]->getCategory());
        self::assertNull($places[1]->getVisitDurationMinutes());
        self::assertNull($places[1]->getLatitude());
        self::assertNull($places[1]->getLongitude());
        self::assertSame(PlaceDifficulty::Unknown, $places[1]->getDifficulty());
        self::assertSame(PriceType::Unknown, $places[1]->getPriceType());
    }

    public function testValidDeleteRemovesPlaceAndCleansOrphanDestination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $destination = $this->createDestination();
        $place = $this->createPlace($destination);
        $placeId = $place->getId();
        $destinationId = $destination->getId();

        $client->request('POST', sprintf('/admin/places/%d/delete', $placeId), [
            '_token' => $this->csrfTokenForClient($client, 'admin_place_delete_'.$placeId),
        ]);

        self::assertResponseRedirects('/admin/places');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Place::class, $placeId));
        self::assertNull($this->entityManager()->find(\App\Entity\Destination::class, $destinationId));
    }

    public function testValidDeleteDetachesCommentsAndCleansLinkedOrphanMedia(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $place = $this->createPlace();
        $media = $this->createImageMedia('Média orphelin du repérage supprimé');
        $this->linkPlaceMedia($place, $media, \App\Enum\MediaRole::Cover, 0);
        $place->setFeaturedImage($media);
        $article = $this->createArticle($admin);
        $comment = $this->createComment($admin, $article)
            ->setArticle(null)
            ->setPlace($place);
        $this->persistAndFlush($place, $comment);
        $placeId = $place->getId();
        $mediaId = $media->getId();
        $commentId = $comment->getId();
        $this->entityManager()->clear();
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/places/%d/delete', $placeId), [
            '_token' => $this->csrfTokenForClient($client, 'admin_place_delete_'.$placeId),
        ]);

        self::assertResponseRedirects('/admin/places');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Place::class, $placeId));
        self::assertNull($this->entityManager()->find(\App\Entity\MediaAsset::class, $mediaId));
        $storedComment = $this->entityManager()->find(\App\Entity\Comment::class, $commentId);
        self::assertInstanceOf(\App\Entity\Comment::class, $storedComment);
        self::assertNull($storedComment->getPlace());
    }
}
