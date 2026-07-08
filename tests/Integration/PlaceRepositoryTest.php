<?php

namespace App\Tests\Integration;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use App\Entity\PlaceTag;
use App\Entity\Tag;
use App\Enum\CategoryType;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Repository\PlaceRepository;

final class PlaceRepositoryTest extends IntegrationTestCase
{
    public function testFindPublishedCombinesFiltersOrdersResultsAndExcludesDrafts(): void
    {
        $destination = $this->destination('Destination filtrée');
        $otherDestination = $this->destination('Autre destination');
        $category = $this->category('Catégorie filtrée');
        $otherCategory = $this->category('Autre catégorie');
        $tag = $this->tag('Tag filtré');
        $otherTag = $this->tag('Autre tag');
        $older = $this->place('Lieu filtré ancien', $destination, $category, ContentStatus::Published, new \DateTimeImmutable('2035-01-01 10:00:00'));
        $newer = $this->place('Lieu filtré récent', $destination, $category, ContentStatus::Published, new \DateTimeImmutable('2035-01-02 10:00:00'));
        $this->linkTag($older, $tag);
        $this->linkTag($newer, $tag);
        $this->linkTag($this->place('Brouillon filtré', $destination, $category), $tag);
        $this->linkTag($this->place('Mauvaise destination', $otherDestination, $category, ContentStatus::Published, new \DateTimeImmutable('2035-01-03')), $tag);
        $this->linkTag($this->place('Mauvaise catégorie', $destination, $otherCategory, ContentStatus::Published, new \DateTimeImmutable('2035-01-03')), $tag);
        $this->linkTag($this->place('Mauvais tag', $destination, $category, ContentStatus::Published, new \DateTimeImmutable('2035-01-03')), $otherTag);
        $this->flushAndClear();

        $results = $this->repository()->findPublished($destination, $category, $tag, 10);

        self::assertSame(
            [$newer->getId(), $older->getId()],
            array_map(static fn (Place $place): ?int => $place->getId(), $results),
        );
    }

    public function testFindPublishedByDestinationIdsHandlesEmptyInputAndExcludesDrafts(): void
    {
        $firstDestination = $this->destination('Destination liste A');
        $secondDestination = $this->destination('Destination liste B');
        $outsideDestination = $this->destination('Destination hors liste');
        $first = $this->place('Lieu liste A', $firstDestination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2035-02-02'));
        $second = $this->place('Lieu liste B', $secondDestination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2035-02-01'));
        $this->place('Brouillon liste A', $firstDestination);
        $this->place('Lieu hors liste', $outsideDestination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2035-02-03'));
        $this->flushAndClear();
        $repository = $this->repository();

        self::assertSame([], $repository->findPublishedByDestinationIds([]));
        $results = $repository->findPublishedByDestinationIds([
            (int) $firstDestination->getId(),
            (int) $secondDestination->getId(),
        ]);

        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (Place $place): ?int => $place->getId(), $results),
        );
    }

    public function testFindFeaturedPublishedAppliesPublicationOrderAndLimit(): void
    {
        $destination = $this->destination('Destination vedette');
        $latest = $this->place('Vedette récente', $destination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2099-03-03'));
        $second = $this->place('Vedette seconde', $destination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2099-03-02'));
        $this->place('Vedette ancienne', $destination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2099-03-01'));
        $this->place('Brouillon plus récent', $destination, publishedAt: new \DateTimeImmutable('2099-03-04'));
        $this->flushAndClear();

        $results = $this->repository()->findFeaturedPublished(2);

        self::assertCount(2, $results);
        self::assertSame(
            [$latest->getId(), $second->getId()],
            array_map(static fn (Place $place): ?int => $place->getId(), $results),
        );
    }

    public function testFindLatestPublishedWithMediaIgnoresDraftsAndNonImageMedia(): void
    {
        $destination = $this->destination('Destination média récent');
        $this->place('Publié sans média', $destination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2035-04-05'));
        $videoOnly = $this->place('Publié vidéo seulement', $destination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2035-04-04'));
        $this->linkMedia($videoOnly, $this->media(MediaType::Video), 0);
        $expected = $this->place('Publié avec image', $destination, status: ContentStatus::Published, publishedAt: new \DateTimeImmutable('2035-04-03'));
        $laterImage = $this->media(MediaType::Image);
        $earlierImage = $this->media(MediaType::Image);
        $this->linkMedia($expected, $laterImage, 2);
        $this->linkMedia($expected, $earlierImage, 0);
        $draft = $this->place('Brouillon avec image', $destination, publishedAt: new \DateTimeImmutable('2035-04-06'));
        $draft->setFeaturedImage($this->media(MediaType::Image));
        $this->flushAndClear();

        $result = $this->repository()->findLatestPublishedWithMediaByDestination($destination);

        self::assertInstanceOf(Place::class, $result);
        self::assertSame($expected->getId(), $result->getId());
        self::assertSame(
            [$earlierImage->getId(), $laterImage->getId()],
            array_map(
                static fn (PlaceMedia $link): ?int => $link->getMediaAsset()?->getId(),
                $result->getMediaLinks()->toArray(),
            ),
        );
    }

    private function repository(): PlaceRepository
    {
        $repository = $this->entityManager->getRepository(Place::class);
        self::assertInstanceOf(PlaceRepository::class, $repository);

        return $repository;
    }

    private function destination(string $name): Destination
    {
        $destination = (new Destination())
            ->setName($name.' '.$this->uniqueToken('destination'))
            ->setSlug($this->uniqueToken('destination-slug'))
            ->setType(DestinationType::Area);
        $this->entityManager->persist($destination);

        return $destination;
    }

    private function category(string $name): Category
    {
        $category = (new Category())
            ->setName($name.' '.$this->uniqueToken('category'))
            ->setSlug($this->uniqueToken('category-slug'))
            ->setType(CategoryType::Place);
        $this->entityManager->persist($category);

        return $category;
    }

    private function tag(string $name): Tag
    {
        $tag = (new Tag())
            ->setName($name.' '.$this->uniqueToken('tag'))
            ->setSlug($this->uniqueToken('tag-slug'));
        $this->entityManager->persist($tag);

        return $tag;
    }

    private function place(
        string $name,
        ?Destination $destination = null,
        ?Category $category = null,
        ContentStatus $status = ContentStatus::Draft,
        ?\DateTimeImmutable $publishedAt = null,
    ): Place {
        $place = (new Place())
            ->setName($name.' '.$this->uniqueToken('place'))
            ->setSlug($this->uniqueToken('place-slug'))
            ->setDestination($destination)
            ->setCategory($category)
            ->setStatus($status)
            ->setPublishedAt($publishedAt);
        $this->entityManager->persist($place);

        return $place;
    }

    private function media(MediaType $type): MediaAsset
    {
        $media = (new MediaAsset())
            ->setTitle($this->uniqueToken('media'))
            ->setMediaType($type);
        $this->entityManager->persist($media);

        return $media;
    }

    private function linkTag(Place $place, Tag $tag): void
    {
        $link = (new PlaceTag())
            ->setPlace($place)
            ->setTag($tag);
        $place->getTagLinks()->add($link);
        $tag->getPlaceTags()->add($link);
        $this->entityManager->persist($link);
    }

    private function linkMedia(Place $place, MediaAsset $media, int $position): void
    {
        $link = (new PlaceMedia())
            ->setPlace($place)
            ->setMediaAsset($media)
            ->setRole(MediaRole::Gallery)
            ->setPosition($position);
        $place->getMediaLinks()->add($link);
        $media->getPlaceLinks()->add($link);
        $this->entityManager->persist($link);
    }

    private function flushAndClear(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
