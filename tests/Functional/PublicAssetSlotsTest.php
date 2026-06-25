<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\CommentStatus;
use Symfony\Component\DomCrawler\Crawler;

final class PublicAssetSlotsTest extends FunctionalTestCase
{
    public function testHomePageLoadsOnlyUniversalAndHomeAssets(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/home.js'],
            ['assets/app.js'],
            [
                'assets/entries/public-listing.js',
                'assets/entries/public-detail.js',
                'assets/entries/related-articles.js',
                'assets/entries/auth.js',
                'assets/entries/profile.js',
                'assets/entries/comments.js',
            ],
        );
    }

    public function testArticleListLoadsOnlyUniversalAndListingAssets(): void
    {
        $client = static::createClient();
        $this->createArticle($this->createUser());

        $crawler = $client->request('GET', '/articles');

        self::assertResponseIsSuccessful();
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/public-listing.js'],
            ['assets/app.js', 'assets/entries/public-listing.js'],
            [
                'assets/entries/public-detail.js',
                'assets/entries/related-articles.js',
                'assets/entries/article-show.js',
                'assets/entries/comments.js',
                'assets/entries/home.js',
            ],
        );
    }

    public function testArticleDetailLoadsPageAssetsWithoutListingAssets(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $article = $this->createArticle($this->createUser());
        $this->createComment($this->createUser(), $article);
        $client->loginUser($user);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-comment-reply-panel]')->count());
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/article-show.js', 'assets/entries/comments.js'],
            ['assets/app.js', 'assets/entries/article-show.js', 'assets/entries/comments.js'],
            ['assets/entries/public-listing.js', 'assets/entries/public-detail.js', 'assets/entries/home.js'],
        );
    }

    public function testPlaceDetailLoadsRelatedArticlesAndCommentAssetsWhenInteractiveCommentsAreDisplayed(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $place = $this->createPublishedPlace($this->createDestination(), $this->createCategory());
        $this->createPlaceComment($this->createUser(), $place);
        $client->loginUser($user);

        $crawler = $client->request('GET', sprintf('/places/%s', $place->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-comment-reply-panel]')->count());
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/comments.js', 'assets/entries/related-articles.js'],
            ['assets/app.js', 'assets/entries/comments.js', 'assets/entries/related-articles.js'],
            ['assets/entries/public-listing.js', 'assets/entries/public-detail.js', 'assets/entries/article-show.js'],
        );
    }

    public function testCommentNotificationsLoadCommentStylesWithoutCommentScript(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/notifications/commentaires');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-comment-replies], [data-comment-replies-toggle], [data-comment-reply-panel], [data-comment-reply-form]')->count());
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/comments.js'],
            ['assets/app.js'],
            ['assets/entries/comments.js', 'assets/entries/public-listing.js', 'assets/entries/article-show.js'],
        );
    }

    public function testHikeDetailLoadsPublicDetailLayoutAssets(): void
    {
        $client = static::createClient();
        $hike = $this->createPublishedHike($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/randonnees/%s', $hike->getSlug()));

        self::assertResponseIsSuccessful();
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/public-detail.js', 'assets/entries/related-articles.js'],
            ['assets/app.js', 'assets/entries/public-detail.js', 'assets/entries/related-articles.js'],
            ['assets/entries/public-listing.js', 'assets/entries/article-show.js', 'assets/entries/comments.js'],
        );
    }

    public function testLoginLoadsAuthAssets(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/auth.js'],
            ['assets/app.js', 'assets/entries/auth.js'],
            ['assets/entries/public-listing.js', 'assets/entries/public-detail.js', 'assets/entries/profile.js'],
        );
    }

    public function testProfileLoadsProfileAssets(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        $this->assertRenderedAssets(
            $crawler,
            ['assets/app.js', 'assets/entries/profile.js'],
            ['assets/app.js', 'assets/entries/profile.js'],
            ['assets/entries/public-listing.js', 'assets/entries/public-detail.js', 'assets/entries/auth.js'],
        );
    }

    /**
     * @param list<string> $expectedStyleEntries
     * @param list<string> $expectedScriptEntries
     * @param list<string> $absentEntries
     */
    private function assertRenderedAssets(Crawler $crawler, array $expectedStyleEntries, array $expectedScriptEntries, array $absentEntries): void
    {
        $renderedAssetUrls = $this->renderedAssetUrls($crawler);
        $expectedStyleUrls = [];

        foreach ($expectedStyleEntries as $entry) {
            foreach ($this->manifestStyleUrls($entry) as $assetUrl) {
                self::assertContains($assetUrl, $renderedAssetUrls, sprintf('Expected asset "%s" for entry "%s".', $assetUrl, $entry));
                $expectedStyleUrls[] = $assetUrl;
            }
        }

        foreach ($expectedScriptEntries as $entry) {
            $assetUrl = $this->manifestScriptUrl($entry);
            if ($assetUrl !== null) {
                self::assertContains($assetUrl, $renderedAssetUrls, sprintf('Expected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }

        foreach ($absentEntries as $entry) {
            $scriptUrl = $this->manifestScriptUrl($entry);
            if ($scriptUrl !== null) {
                self::assertNotContains($scriptUrl, $renderedAssetUrls, sprintf('Unexpected asset "%s" for entry "%s".', $scriptUrl, $entry));
            }

            foreach ($this->manifestStyleUrls($entry) as $assetUrl) {
                if (in_array($assetUrl, $expectedStyleUrls, true)) {
                    continue;
                }

                self::assertNotContains($assetUrl, $renderedAssetUrls, sprintf('Unexpected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }

        self::assertSame(
            $renderedAssetUrls,
            array_values(array_unique($renderedAssetUrls)),
            'Rendered Vite asset URLs must not be duplicated.',
        );
    }

    /**
     * @return list<string>
     */
    private function renderedAssetUrls(Crawler $crawler): array
    {
        $urls = [];

        foreach ($crawler->filter('link[href], script[src]') as $node) {
            $url = $node->attributes->getNamedItem('href')?->nodeValue
                ?? $node->attributes->getNamedItem('src')?->nodeValue;

            if (is_string($url) && str_starts_with($url, '/build/')) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function manifestStyleUrls(string $entry): array
    {
        $chunk = $this->manifest()[$entry] ?? null;
        self::assertIsArray($chunk, sprintf('Missing Vite manifest entry "%s".', $entry));

        $urls = [];

        foreach ($chunk['css'] ?? [] as $cssFile) {
            self::assertIsString($cssFile);
            $urls[] = '/build/'.$cssFile;
        }

        return $urls;
    }

    private function manifestScriptUrl(string $entry): ?string
    {
        $chunk = $this->manifest()[$entry] ?? null;
        self::assertIsArray($chunk, sprintf('Missing Vite manifest entry "%s".', $entry));

        if (!isset($chunk['file'])) {
            return null;
        }

        self::assertIsString($chunk['file']);

        return '/build/'.$chunk['file'];
    }

    private function createPlaceComment(User $author, Place $place): Comment
    {
        $now = new \DateTimeImmutable('-1 hour');
        $comment = (new Comment())
            ->setAuthor($author)
            ->setPlace($place)
            ->setContent('Commentaire fonctionnel assez long pour un lieu.')
            ->setStatus(CommentStatus::Approved)
            ->setPublishedAt($now)
            ->setApprovedAt($now);

        $this->persistAndFlush($comment);

        return $comment;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        static $manifest = null;

        if (is_array($manifest)) {
            return $manifest;
        }

        $manifestPath = dirname(__DIR__, 2).'/public/build/manifest.json';
        self::assertFileExists($manifestPath, 'Run "docker compose run --rm node npm run build" before asset rendering tests.');

        $decoded = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $manifest = $decoded;
    }
}
