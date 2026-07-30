<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;

final class SeoControllerTest extends FunctionalTestCase
{
    private const string PUBLIC_ORIGIN = 'https://estela-exploration.fr';

    public function testFooterContainsOnlyExpectedPublicNavigationAndIsAbsentFromAdmin(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('footer.site-footer')->count());
        self::assertSame('Voyager entre mer, montagne et lumière.', trim($crawler->filter('.site-footer__brand p')->text()));

        $footerLinks = $this->linksByText($crawler->filter('footer.site-footer'));
        self::assertSame('/destinations', $footerLinks['Destinations'] ?? null);
        self::assertSame('/randonnees', $footerLinks['Randonnées'] ?? null);
        self::assertSame('/visites', $footerLinks['Visites'] ?? null);
        self::assertSame('/articles', $footerLinks['Articles'] ?? null);
        self::assertSame('/places', $footerLinks['Lieux'] ?? null);
        self::assertSame('/plan-du-site', $footerLinks['Plan du site'] ?? null);

        $youtube = $crawler->filter('footer.site-footer a[href="https://www.youtube.com/channel/UCKv62tsRzbWy_rfm6_oKM-A"]');
        self::assertSame(1, $youtube->count());
        self::assertSame('_blank', $youtube->attr('target'));
        self::assertSame('noopener noreferrer', $youtube->attr('rel'));
        self::assertSame('Voir la chaîne YouTube Estela', $youtube->attr('aria-label'));
        self::assertSame(1, $youtube->filter('svg.site-footer__social-icon--youtube[aria-hidden="true"]')->count());

        $client->loginUser($this->createVerifiedAdmin());
        $adminCrawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $adminCrawler->filter('footer.site-footer')->count());
    }

    public function testPlanDuSiteIsPublicUsefulAndLinkedFromFooter(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/plan-du-site');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plan du site');
        self::assertSame(1, $crawler->filter('link[rel="canonical"][href="'.self::PUBLIC_ORIGIN.'/plan-du-site"]')->count());

        $mainLinks = $this->linksByText($crawler->filter('main.site-map-page'));
        self::assertSame('/', $mainLinks['Accueil'] ?? null);
        self::assertSame('/destinations', $mainLinks['Destinations'] ?? null);
        self::assertSame('/randonnees', $mainLinks['Randonnées'] ?? null);
        self::assertSame('/visites', $mainLinks['Visites'] ?? null);
        self::assertSame('/articles', $mainLinks['Articles'] ?? null);
        self::assertSame('/places', $mainLinks['Lieux'] ?? null);
        self::assertSame('/plan-du-site', $mainLinks['Plan du site'] ?? null);

        foreach (['/admin', '/login', '/register', '/profile', '/notifications'] as $privatePath) {
            self::assertNotContains($privatePath, array_values($mainLinks));
        }

        self::assertSame(1, $crawler->filter('footer.site-footer a[href="/plan-du-site"]')->count());
    }

    public function testSitemapContainsCanonicalPublishedContentOnlyWithoutDuplicates(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $destination = $this->createDestination('Destination sitemap');
        $article = $this->createArticle($admin, $destination);
        $draftArticle = $this->createArticle($admin, $destination)->setStatus(ContentStatus::Draft);
        $this->persistAndFlush($draftArticle);
        $hike = $this->createPublishedHike($admin, $destination);
        $draftHike = $this->createHikeDraft($admin, $destination);
        $cityVisit = $this->createPublishedCityVisit($admin, $destination);
        $draftCityVisit = $this->createCityVisitDraft($admin, $destination);
        $place = $this->createPublishedPlace($destination, $this->createCategory());
        $draftPlace = $this->createPlace($destination);

        $client->request('GET', 'https://untrusted.example/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertSame('application/xml; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($content));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locations = [];
        foreach ($xpath->query('//sm:url/sm:loc') ?: [] as $location) {
            $locations[] = $location->textContent;
        }
        foreach ($locations as $location) {
            self::assertStringStartsWith(self::PUBLIC_ORIGIN.'/', $location);
        }

        foreach ([
            self::PUBLIC_ORIGIN.'/',
            self::PUBLIC_ORIGIN.'/destinations',
            self::PUBLIC_ORIGIN.'/randonnees',
            self::PUBLIC_ORIGIN.'/visites',
            self::PUBLIC_ORIGIN.'/articles',
            self::PUBLIC_ORIGIN.'/places',
            self::PUBLIC_ORIGIN.'/plan-du-site',
            self::PUBLIC_ORIGIN.'/destinations/'.$destination->getSlug(),
            self::PUBLIC_ORIGIN.'/articles/'.$article->getSlug(),
            self::PUBLIC_ORIGIN.'/randonnees/'.$hike->getSlug(),
            self::PUBLIC_ORIGIN.'/visites-de-ville/'.$cityVisit->getSlug(),
            self::PUBLIC_ORIGIN.'/places/'.$place->getSlug(),
        ] as $expectedLocation) {
            self::assertContains($expectedLocation, $locations);
        }

        foreach ([
            self::PUBLIC_ORIGIN.'/articles/'.$draftArticle->getSlug(),
            self::PUBLIC_ORIGIN.'/randonnees/'.$draftHike->getSlug(),
            self::PUBLIC_ORIGIN.'/visites-de-ville/'.$draftCityVisit->getSlug(),
            self::PUBLIC_ORIGIN.'/places/'.$draftPlace->getSlug(),
        ] as $excludedLocation) {
            self::assertNotContains($excludedLocation, $locations);
        }

        self::assertCount(count(array_unique($locations)), $locations);
        self::assertStringNotContainsString('/admin', $content);
        self::assertStringNotContainsString('/login', $content);
        self::assertStringNotContainsString('/register', $content);
        self::assertStringNotContainsString('/profile', $content);
        self::assertStringNotContainsString('/notifications', $content);
        self::assertStringNotContainsString('localhost', $content);
        self::assertStringNotContainsString('untrusted.example', $content);
        self::assertStringNotContainsString('https://www.', $content);
        self::assertStringNotContainsString('<changefreq>', $content);
        self::assertStringNotContainsString('<priority>', $content);

        $articleLocation = self::PUBLIC_ORIGIN.'/articles/'.$article->getSlug();
        $lastModifiedNodes = $xpath->query(sprintf(
            '//sm:url[sm:loc="%s"]/sm:lastmod',
            $articleLocation,
        ));
        self::assertNotFalse($lastModifiedNodes);
        self::assertSame(1, $lastModifiedNodes->length);
        self::assertSame($article->getUpdatedAt()?->format(\DateTimeInterface::ATOM), $lastModifiedNodes->item(0)?->textContent);
    }

    public function testRobotsUsesConfiguredPublicOriginAndAdvertisesSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://untrusted.example/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertSame('text/plain; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("User-agent: *\n", $content);
        self::assertStringContainsString("Allow: /\n", $content);
        self::assertStringContainsString("Disallow: /admin/\n", $content);
        self::assertStringContainsString('Sitemap: '.self::PUBLIC_ORIGIN.'/sitemap.xml', $content);
        self::assertStringNotContainsString('localhost', $content);
        self::assertStringNotContainsString('untrusted.example', $content);
    }

    public function testCanonicalAndOpenGraphUrlIgnoreHostSchemeFrontControllerAndTrackingParameters(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://untrusted.example/?utm_source=test&gclid=tracking');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertSame(self::PUBLIC_ORIGIN.'/', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame(self::PUBLIC_ORIGIN.'/', $crawler->filter('meta[property="og:url"]')->attr('content'));

        $crawler = $client->request('GET', 'http://untrusted.example/randonnees?utm_medium=email&fbclid=tracking');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertSame(self::PUBLIC_ORIGIN.'/randonnees', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame(self::PUBLIC_ORIGIN.'/randonnees', $crawler->filter('meta[property="og:url"]')->attr('content'));
    }

    #[DataProvider('canonicalRedirectProvider')]
    public function testProductionVariantsRedirectInOneHopAndPreserveTheQueryString(string $requestedUrl, string $expectedUrl): void
    {
        $client = static::createClient();
        $client->request('GET', $requestedUrl);

        self::assertResponseRedirects($expectedUrl, 308);
    }

    /** @return iterable<string, array{string, string}> */
    public static function canonicalRedirectProvider(): iterable
    {
        yield 'http' => [
            'http://estela-exploration.fr/articles?utm_source=test',
            self::PUBLIC_ORIGIN.'/articles?utm_source=test',
        ];
        yield 'www' => [
            'https://www.estela-exploration.fr/randonnees?source=newsletter',
            self::PUBLIC_ORIGIN.'/randonnees?source=newsletter',
        ];
        yield 'front controller' => [
            'https://estela-exploration.fr/index.php/?gclid=tracking',
            self::PUBLIC_ORIGIN.'/?gclid=tracking',
        ];
        yield 'combined www and front controller' => [
            'http://www.estela-exploration.fr/index.php/articles?source=newsletter',
            self::PUBLIC_ORIGIN.'/articles?source=newsletter',
        ];
    }

    public function testAuthenticationAndResetPagesAreNoindexFollowWithoutCanonical(): void
    {
        $client = static::createClient();

        foreach (['/login', '/register', '/reset-password', '/reset-password/check-email'] as $path) {
            $crawler = $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSame(1, $crawler->filter('meta[name="robots"][content="noindex, follow"]')->count(), $path);
            self::assertSame(0, $crawler->filter('meta[name="robots"][content*="nofollow"]')->count(), $path);
            self::assertSame(0, $crawler->filter('link[rel="canonical"]')->count(), $path);
            self::assertSame(0, $crawler->filter('meta[property="og:url"]')->count(), $path);
        }

        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('meta[name="robots"][content="noindex, follow"]')->count());
        self::assertSame(0, $crawler->filter('link[rel="canonical"]')->count());
    }

    /** @return array<string, string> */
    private function linksByText(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        $links = [];
        foreach ($crawler->filter('a') as $link) {
            $label = trim((string) $link->textContent);
            $href = $link->getAttribute('href');
            if ($label !== '' && $href !== '') {
                $links[$label] = $href;
            }
        }

        return $links;
    }
}
