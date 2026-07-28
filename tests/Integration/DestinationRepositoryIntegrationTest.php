<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\ArticleDestination;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Repository\DestinationRepository;

final class DestinationRepositoryIntegrationTest extends IntegrationTestCase
{
    public function testFindBySlugReturnsDestinationWithParentAndChildren(): void
    {
        $region = $this->destination('Region repo', DestinationType::Region);
        $department = $this->destination('Departement repo', DestinationType::Department, $region, '99');
        $city = $this->destination('Commune repo', DestinationType::City, $department, '99001');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->repository()->findBySlug((string) $department->getSlug());

        self::assertInstanceOf(Destination::class, $stored);
        self::assertSame($region->getId(), $stored->getParent()?->getId());
        self::assertSame([$city->getId()], array_map(static fn (Destination $child): ?int => $child->getId(), $stored->getChildren()->toArray()));
    }

    public function testFindDestinationAndDescendantIdsIncludesFullTree(): void
    {
        $country = $this->destination('Pays repo', DestinationType::Country);
        $region = $this->destination('Region enfant repo', DestinationType::Region, $country);
        $department = $this->destination('Departement enfant repo', DestinationType::Department, $region, '98');
        $city = $this->destination('Commune enfant repo', DestinationType::City, $department, '98001');
        $this->entityManager->flush();

        $ids = $this->repository()->findDestinationAndDescendantIds($country);

        self::assertContains($country->getId(), $ids);
        self::assertContains($region->getId(), $ids);
        self::assertContains($department->getId(), $ids);
        self::assertContains($city->getId(), $ids);
    }

    public function testFindWithParentsByIdsHydratesParentChain(): void
    {
        $country = $this->destination('Pays parents repo', DestinationType::Country);
        $region = $this->destination('Region parents repo', DestinationType::Region, $country);
        $department = $this->destination('Departement parents repo', DestinationType::Department, $region, '97');
        $city = $this->destination('Commune parents repo', DestinationType::City, $department, '97001');
        $this->entityManager->flush();

        $destinations = $this->repository()->findWithParentsByIds([(int) $city->getId()]);

        self::assertCount(1, $destinations);
        self::assertSame($department->getId(), $destinations[0]->getParent()?->getId());
        self::assertSame($region->getId(), $destinations[0]->getParent()?->getParent()?->getId());
        self::assertSame($country->getId(), $destinations[0]->getParent()?->getParent()?->getParent()?->getId());
    }

    public function testCumulativeCountsKeepHomonymCommunesSeparatedByGeographicDestination(): void
    {
        $department = $this->destination('Departement homonyme repo', DestinationType::Department, null, '96');
        $firstCity = $this->destination('Paris repo', DestinationType::City, $department, '96001');
        $secondCity = $this->destination('Paris repo bis', DestinationType::City, $department, '96002');
        $firstHike = $this->hike('Rando commune 1', $firstCity);
        $secondHike = $this->hike('Rando commune 2', $secondCity);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedDepartment = $this->repository()->findBySlug((string) $department->getSlug());
        self::assertInstanceOf(Destination::class, $storedDepartment);
        $counts = $this->repository()->findCumulativeContentCountsForTree([$storedDepartment]);

        self::assertSame(1, $counts[(int) $firstCity->getId()]['hikes'] ?? null);
        self::assertSame(1, $counts[(int) $secondCity->getId()]['hikes'] ?? null);
        self::assertSame(2, $counts[(int) $department->getId()]['hikes'] ?? null);
        self::assertNotSame($firstHike->getGeographicDestination()?->getId(), $secondHike->getGeographicDestination()?->getId());
    }

    public function testCumulativeArticleCountsDeduplicateAnArticleLinkedToSeveralDescendants(): void
    {
        $country = $this->destination('Pays articles uniques', DestinationType::Country);
        $department = $this->destination('Département articles uniques', DestinationType::Department, $country, '95');
        $firstCity = $this->destination('Première ville articles uniques', DestinationType::City, $department, '95001');
        $secondCity = $this->destination('Seconde ville articles uniques', DestinationType::City, $department, '95002');
        $article = $this->article('Article multi-destinations repo');
        $this->entityManager->persist(
            (new ArticleDestination())
                ->setArticle($article)
                ->setDestination($firstCity),
        );
        $this->entityManager->persist(
            (new ArticleDestination())
                ->setArticle($article)
                ->setDestination($secondCity),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedCountry = $this->repository()->findBySlug((string) $country->getSlug());
        self::assertInstanceOf(Destination::class, $storedCountry);
        $counts = $this->repository()->findCumulativeContentCountsForTree([$storedCountry]);

        self::assertSame(1, $counts[(int) $firstCity->getId()]['articles'] ?? null);
        self::assertSame(1, $counts[(int) $secondCity->getId()]['articles'] ?? null);
        self::assertSame(1, $counts[(int) $department->getId()]['articles'] ?? null);
        self::assertSame(1, $counts[(int) $country->getId()]['articles'] ?? null);
    }

    private function destination(
        string $name,
        DestinationType $type,
        ?Destination $parent = null,
        ?string $code = null,
    ): Destination {
        $token = $this->uniqueToken('destination-repo');
        $destination = (new Destination())
            ->setName($name.' '.$token)
            ->setSlug(strtolower(str_replace('_', '-', $token)))
            ->setType($type)
            ->setParent($parent)
            ->setCode($code);

        $this->entityManager->persist($destination);

        return $destination;
    }

    private function hike(string $title, Destination $geographicDestination): HikeDraft
    {
        $token = $this->uniqueToken('hike-repo');
        $hike = (new HikeDraft())
            ->setTitle($title.' '.$token)
            ->setSlug('hike-repo-'.$token)
            ->setStatus(HikeDraftStatus::Finished)
            ->setGeographicDestination($geographicDestination)
            ->setFinishedAt(new \DateTimeImmutable('-1 hour'));

        $this->entityManager->persist($hike);

        return $hike;
    }

    private function article(string $title): Article
    {
        $token = $this->uniqueToken('article-repo');
        $article = (new Article())
            ->setTitle($title.' '.$token)
            ->setSlug('article-repo-'.$token)
            ->setExcerpt('Extrait de test')
            ->setContent('<p>Contenu de test.</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        $this->entityManager->persist($article);

        return $article;
    }

    private function repository(): DestinationRepository
    {
        $repository = $this->service(DestinationRepository::class);
        self::assertInstanceOf(DestinationRepository::class, $repository);

        return $repository;
    }
}
