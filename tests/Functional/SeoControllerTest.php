<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
use DOMDocument;
use DOMXPath;

final class SeoControllerTest extends FunctionalTestCase
{
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
        self::assertSame(1, $crawler->filter('link[rel="canonical"][href="http://localhost/plan-du-site"]')->count());

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

        $client->request('GET', 'https://estela.example/sitemap.xml');

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

        foreach ([
            'https://estela.example/',
            'https://estela.example/destinations',
            'https://estela.example/randonnees',
            'https://estela.example/visites',
            'https://estela.example/articles',
            'https://estela.example/places',
            'https://estela.example/plan-du-site',
            'https://estela.example/destinations/'.$destination->getSlug(),
            'https://estela.example/articles/'.$article->getSlug(),
            'https://estela.example/randonnees/'.$hike->getSlug(),
            'https://estela.example/visites-de-ville/'.$cityVisit->getSlug(),
            'https://estela.example/places/'.$place->getSlug(),
        ] as $expectedLocation) {
            self::assertContains($expectedLocation, $locations);
        }

        foreach ([
            'https://estela.example/articles/'.$draftArticle->getSlug(),
            'https://estela.example/randonnees/'.$draftHike->getSlug(),
            'https://estela.example/visites-de-ville/'.$draftCityVisit->getSlug(),
            'https://estela.example/places/'.$draftPlace->getSlug(),
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
        self::assertStringNotContainsString('<changefreq>', $content);
        self::assertStringNotContainsString('<priority>', $content);

        $articleLocation = 'https://estela.example/articles/'.$article->getSlug();
        $lastModifiedNodes = $xpath->query(sprintf(
            '//sm:url[sm:loc="%s"]/sm:lastmod',
            $articleLocation,
        ));
        self::assertNotFalse($lastModifiedNodes);
        self::assertSame(1, $lastModifiedNodes->length);
        self::assertSame($article->getUpdatedAt()?->format(\DateTimeInterface::ATOM), $lastModifiedNodes->item(0)?->textContent);
    }

    public function testRobotsUsesCurrentRequestHostAndAdvertisesSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://estela.example/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertSame('text/plain; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("User-agent: *\n", $content);
        self::assertStringContainsString("Allow: /\n", $content);
        self::assertStringContainsString("Disallow: /admin/\n", $content);
        self::assertStringContainsString('Sitemap: https://estela.example/sitemap.xml', $content);
        self::assertStringNotContainsString('localhost', $content);
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
