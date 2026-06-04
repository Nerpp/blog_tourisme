<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\ArticleDestination;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\Place;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Service\OrphanLocationCleanupService;

final class OrphanLocationCleanupServiceTest extends IntegrationTestCase
{
    public function testIgnoresNullOrUnpersistedDestination(): void
    {
        $service = $this->cleanupService();

        self::assertSame([
            'status' => 'ignored',
            'reason' => 'null',
            'usageCount' => 0,
            'childrenCount' => 0,
        ], $service->cleanupDestinationIfOrphan(null));

        self::assertSame('ignored', $service->cleanupDestinationIfOrphan($this->destination('Transient'))['status']);
    }

    public function testDeletesPersistedOrphanDestination(): void
    {
        $destination = $this->persistDestination('Orphan commune', DestinationType::City, code: '66001');
        $id = $destination->getId();

        $result = $this->cleanupService()->cleanupDestinationIfOrphan($destination);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame('deleted', $result['status']);
        self::assertSame('orphan', $result['reason']);
        self::assertNull($this->entityManager->find(Destination::class, $id));
    }

    public function testKeepsDestinationReferencedByDraftsPlacesAndArticleLinks(): void
    {
        $user = $this->createUser();
        $hikeDestination = $this->persistDestination('Hike commune');
        $cityDestination = $this->persistDestination('City commune');
        $placeDestination = $this->persistDestination('Place commune');
        $articleDestination = $this->persistDestination('Editorial destination', DestinationType::Area);

        $hike = (new HikeDraft())
            ->setTitle('Hike linked')
            ->setSlug($this->uniqueToken('hike'))
            ->setStatus(HikeDraftStatus::Draft)
            ->setCreatedBy($user)
            ->setGeographicDestination($hikeDestination);
        $cityVisit = (new CityVisitDraft())
            ->setTitle('City linked')
            ->setSlug($this->uniqueToken('city'))
            ->setStatus(CityVisitDraftStatus::Draft)
            ->setCreatedBy($user)
            ->setGeographicDestination($cityDestination);
        $place = (new Place())
            ->setName('Linked place')
            ->setSlug($this->uniqueToken('place'))
            ->setDestination($placeDestination)
            ->setStatus(ContentStatus::Draft)
            ->setDifficulty(PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::Unknown);
        $article = (new Article())
            ->setAuthor($user)
            ->setTitle('Linked article')
            ->setSlug($this->uniqueToken('article'))
            ->setContent('<p>Article</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $articleLink = (new ArticleDestination())
            ->setArticle($article)
            ->setDestination($articleDestination);

        $this->entityManager->persist($user);
        $this->entityManager->persist($hike);
        $this->entityManager->persist($cityVisit);
        $this->entityManager->persist($place);
        $this->entityManager->persist($article);
        $this->entityManager->persist($articleLink);
        $this->entityManager->flush();

        foreach ([$hikeDestination, $cityDestination, $placeDestination, $articleDestination] as $destination) {
            $result = $this->cleanupService()->cleanupDestinationIfOrphan($destination);
            self::assertSame('kept', $result['status']);
            self::assertSame('used', $result['reason']);
            self::assertSame(1, $result['usageCount']);
        }
    }

    public function testKeepsParentWithChildAndDeletesParentAfterChildIsRemoved(): void
    {
        $parent = $this->persistDestination('Parent area', DestinationType::Area);
        $child = $this->persistDestination('Child city', DestinationType::City, $parent, '66002');

        $result = $this->cleanupService()->cleanupDestinationIfOrphan($parent);

        self::assertSame('kept', $result['status']);
        self::assertSame('children', $result['reason']);
        self::assertSame(1, $result['childrenCount']);

        $this->entityManager->remove($child);
        $this->entityManager->flush();

        $result = $this->cleanupService()->cleanupDestinationIfOrphan($parent);
        $this->entityManager->flush();

        self::assertSame('deleted', $result['status']);
    }

    private function cleanupService(): OrphanLocationCleanupService
    {
        $service = $this->service(OrphanLocationCleanupService::class);
        self::assertInstanceOf(OrphanLocationCleanupService::class, $service);

        return $service;
    }

    private function persistDestination(
        string $name,
        DestinationType $type = DestinationType::City,
        ?Destination $parent = null,
        ?string $code = null,
    ): Destination {
        $destination = $this->destination($name, $type, $parent, $code);
        $this->entityManager->persist($destination);
        $this->entityManager->flush();

        return $destination;
    }

    private function destination(
        string $name,
        DestinationType $type = DestinationType::City,
        ?Destination $parent = null,
        ?string $code = null,
    ): Destination {
        $token = $this->uniqueToken('destination');

        return (new Destination())
            ->setName($name.' '.$token)
            ->setSlug(strtolower($token))
            ->setType($type)
            ->setParent($parent)
            ->setCode($code);
    }
}
