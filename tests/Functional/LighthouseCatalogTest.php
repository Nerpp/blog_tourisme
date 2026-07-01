<?php

namespace App\Tests\Functional;

use App\Command\AssertLighthouseDatabaseCommand;
use App\DataFixtures\ArticleFixtures;
use App\DataFixtures\CityVisitFixtures;
use App\DataFixtures\DestinationFixtures;
use App\DataFixtures\HikeFixtures;
use App\DataFixtures\PlaceFixtures;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class LighthouseCatalogTest extends FunctionalTestCase
{
    public function testCatalogContainsUniqueReachableFixturePages(): void
    {
        $client = static::createClient();
        $pages = $this->catalogPages();
        $ids = [];
        $paths = [];

        foreach ($pages as $page) {
            self::assertArrayHasKey('id', $page);
            self::assertArrayHasKey('label', $page);
            self::assertArrayHasKey('type', $page);
            self::assertArrayHasKey('path', $page);
            self::assertIsString($page['id']);
            self::assertIsString($page['label']);
            self::assertIsString($page['type']);
            self::assertIsString($page['path']);
            self::assertStringStartsWith('/', $page['path']);
            self::assertFalse(str_starts_with($page['path'], '//'));

            $ids[] = $page['id'];
            $paths[] = $page['path'];

            $crawler = $client->request('GET', $page['path']);
            self::assertResponseStatusCodeSame(200, $page['path']);
            self::assertFalse($client->getResponse()->isRedirection(), $page['path']);
            self::assertGreaterThan(0, $crawler->filter('h1')->count(), sprintf('Titre public manquant pour %s.', $page['path']));
            self::assertNotSame('', trim($crawler->filter('h1')->first()->text()), sprintf('Titre public vide pour %s.', $page['path']));
        }

        self::assertSame($ids, array_values(array_unique($ids)), 'Les identifiants Lighthouse doivent être uniques.');
        self::assertSame($paths, array_values(array_unique($paths)), 'Les chemins Lighthouse doivent être uniques.');
        self::assertContains('/articles/'.ArticleFixtures::LIGHTHOUSE_SLUG, $paths);
        self::assertContains('/destinations/'.DestinationFixtures::LIGHTHOUSE_SLUG, $paths);
        self::assertContains('/randonnees/'.HikeFixtures::LIGHTHOUSE_SLUG, $paths);
        self::assertContains('/visites-de-ville/'.CityVisitFixtures::LIGHTHOUSE_SLUG, $paths);
        self::assertContains('/places/'.PlaceFixtures::LIGHTHOUSE_SLUG, $paths);
    }

    public function testCatalogDetailFixturesArePublicAndHaveLocalCoverMedia(): void
    {
        static::createClient();
        $container = static::getContainer();
        $now = new \DateTimeImmutable();

        $article = $container->get(ArticleRepository::class)->findPublishedBySlug(ArticleFixtures::LIGHTHOUSE_SLUG);
        self::assertNotNull($article);
        self::assertSame(ContentStatus::Published, $article->getStatus());
        self::assertNotNull($article->getPublishedAt());
        self::assertLessThanOrEqual($now, $article->getPublishedAt());
        self::assertNotNull($article->getAuthor());
        self::assertNotNull($article->getCategory());
        self::assertNotNull($article->getFeaturedImage());
        $this->assertLocalMediaAvailable($article->getFeaturedImage());

        $destination = $container->get(DestinationRepository::class)->findBySlug(DestinationFixtures::LIGHTHOUSE_SLUG);
        self::assertNotNull($destination);
        self::assertNotNull($destination->getParent());

        $hike = $container->get(HikeDraftRepository::class)->findPublicBySlug(HikeFixtures::LIGHTHOUSE_SLUG);
        self::assertNotNull($hike);
        self::assertContains($hike->getStatus(), [HikeDraftStatus::Finished, HikeDraftStatus::Converted]);
        self::assertNotNull($hike->getFinishedAt());
        self::assertLessThanOrEqual($now, $hike->getFinishedAt());
        self::assertNotNull($hike->getCreatedBy());
        self::assertNotNull($hike->getGeographicDestination());
        self::assertGreaterThan(0, $hike->getMediaLinks()->count());
        $hikeMediaLink = $hike->getMediaLinks()->first();
        self::assertNotFalse($hikeMediaLink);
        $this->assertLocalMediaAvailable($hikeMediaLink->getMediaAsset());

        $visit = $container->get(CityVisitDraftRepository::class)->findPublicBySlug(CityVisitFixtures::LIGHTHOUSE_SLUG);
        self::assertNotNull($visit);
        self::assertContains($visit->getStatus(), [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted]);
        self::assertNotNull($visit->getFinishedAt());
        self::assertLessThanOrEqual($now, $visit->getFinishedAt());
        self::assertNotNull($visit->getCreatedBy());
        self::assertNotNull($visit->getGeographicDestination());
        self::assertGreaterThan(0, $visit->getMediaLinks()->count());
        $visitMediaLink = $visit->getMediaLinks()->first();
        self::assertNotFalse($visitMediaLink);
        $this->assertLocalMediaAvailable($visitMediaLink->getMediaAsset());

        $place = $container->get(PlaceRepository::class)->findPublishedBySlug(PlaceFixtures::LIGHTHOUSE_SLUG);
        self::assertNotNull($place);
        self::assertSame(ContentStatus::Published, $place->getStatus());
        self::assertNotNull($place->getPublishedAt());
        self::assertLessThanOrEqual($now, $place->getPublishedAt());
        self::assertNotNull($place->getDestination());
        self::assertNotNull($place->getCategory());
        self::assertNotNull($place->getFeaturedImage());
        $this->assertLocalMediaAvailable($place->getFeaturedImage());
    }

    public function testDatabaseGuardAcceptsTheSharedTestDatabase(): void
    {
        static::createClient();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:lighthouse:assert-safe-database'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Base Lighthouse sûre confirmée', $tester->getDisplay());
        self::assertStringContainsString(AssertLighthouseDatabaseCommand::EXPECTED_ENVIRONMENT, $tester->getDisplay());
        self::assertStringContainsString('app_test', $tester->getDisplay());
    }

    /** @return list<array{id: string, label: string, type: string, path: string}> */
    private function catalogPages(): array
    {
        $path = dirname(__DIR__, 2).'/config/lighthouse-pages.json';
        self::assertFileExists($path);
        $pages = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($pages);
        self::assertNotSame([], $pages);

        return $pages;
    }

    private function assertLocalMediaAvailable(MediaAsset $media): void
    {
        $paths = array_filter([$media->getFilePath(), $media->getThumbnailPath()]);
        $variants = $media->getVariants() ?? [];
        array_walk_recursive($variants, static function (mixed $value) use (&$paths): void {
            if (is_string($value) && str_starts_with($value, '/uploads/')) {
                $paths[] = $value;
            }
        });

        self::assertNotSame([], $paths, sprintf('Aucun chemin local pour le média "%s".', $media->getTitle()));
        self::assertTrue(
            array_any($paths, static fn (string $path): bool => is_file(dirname(__DIR__, 2).'/public'.$path)),
            sprintf('Aucun fichier local disponible pour le média "%s".', $media->getTitle()),
        );
    }
}
