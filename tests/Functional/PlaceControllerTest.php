<?php

namespace App\Tests\Functional;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlaceControllerTest extends FunctionalTestCase
{
    public function testPlaceIndexIsAccessible(): void
    {
        $client = static::createClient();
        $place = $this->createPublishedPlace($this->createDestination(), $this->createCategory());

        $crawler = $client->request('GET', '/places');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Repérages');
        self::assertSelectorTextContains('body', (string) $place->getName());
        self::assertCount(1, $crawler->filter('.content-card h2 a:contains("'.$place->getName().'")'));
        self::assertCount(0, $crawler->filter('.content-card h3'));
    }

    public function testPublishedPlaceIsAccessibleWithoutMedia(): void
    {
        $client = static::createClient();
        $place = $this->createPublishedPlace($this->createDestination(), $this->createCategory());

        $client->request('GET', sprintf('/places/%s', $place->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $place->getName());
    }

    public function testUnknownPlaceReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', '/places/lieu-inconnu-fonctionnel');
    }

    public function testDraftPlaceIsNotVisiblePublicly(): void
    {
        $client = static::createClient();
        $place = $this->createPlace($this->createDestination(), $this->createCategory());
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', sprintf('/places/%s', $place->getSlug()));
    }

    public function testInvalidCommentSortFallsBackWithoutServerError(): void
    {
        $client = static::createClient();
        $place = $this->createPublishedPlace($this->createDestination(), $this->createCategory());

        $client->request('GET', sprintf('/places/%s?comments_sort=unexpected', $place->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $place->getName());
    }
}
