<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;

final class PublicNavigationPantherTest extends PantherTestCase
{
    public function testRelatedArticlesOpenDirectlyAndKeepAValidatedReturnLink(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();

        foreach ([
            [
                'path' => '/randonnees/boucle-du-canigou-decouverte',
                'from' => 'hike',
                'return_text' => '← Retour à la randonnée : Boucle du Canigou découverte',
            ],
            [
                'path' => '/visites-de-ville/visiter-collioure-a-pied',
                'from' => 'city_visit',
                'return_text' => '← Retour à la visite : Visiter Collioure à pied',
            ],
        ] as $context) {
            $client->request('GET', $context['path']);
            $client->waitFor('a.related-article-card__button');

            $this->assertPageHasBuiltStyles($client, 'assets/entries/related-articles.js');
            $this->assertPageDoesNotHaveBuiltScripts($client, 'assets/entries/related-articles.js');
            self::assertSelectorNotExists('.related-article-modal, .js-related-article-open');
            $articleLink = $webDriver->findElement(WebDriverBy::cssSelector('a.related-article-card__button'));
            self::assertSame('Lire l’article', trim($articleLink->getText()));
            self::assertNull($articleLink->getAttribute('target'));
            $articleUrl = (string) $articleLink->getAttribute('href');
            parse_str((string) parse_url($articleUrl, PHP_URL_QUERY), $query);
            self::assertSame($context['from'], $query['from'] ?? null);
            self::assertSame(basename($context['path']), $query['source'] ?? null);

            $articleLink->click();
            $client->waitFor('.article-show-context-return');

            self::assertStringStartsWith('/articles/', (string) parse_url($client->getCurrentURL(), PHP_URL_PATH));
            $returnLink = $webDriver->findElement(WebDriverBy::cssSelector('.article-show-context-return'));
            self::assertSame($context['return_text'], trim($returnLink->getText()));
            self::assertSame($context['path'], parse_url((string) $returnLink->getAttribute('href'), PHP_URL_PATH));
            self::assertNull($returnLink->getAttribute('target'));

            $returnLink->click();
            $client->waitFor('a.related-article-card__button');
            self::assertSame($context['path'], parse_url($client->getCurrentURL(), PHP_URL_PATH));
        }

        $this->assertNoBrowserSevereErrors($client);
    }

    public function testPublicNavigationCanOpenArticlesPage(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $client->request('GET', '/');

        self::assertSelectorTextContains('footer.site-footer', 'Voyager entre mer, montagne et lumière.');
        $this->assertPageHasBuiltStyles($client, 'assets/app.js', 'assets/entries/home.js');
        $this->assertPageHasBuiltScripts($client, 'assets/app.js');
        $this->assertPageDoesNotHaveBuiltAssets(
            $client,
            'assets/entries/public-listing.js',
            'assets/entries/public-detail.js',
            'assets/entries/comments.js',
        );
        $this->assertNoBrowserSevereErrors($client);

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.navbar-nav a[href="/articles"]'))->click();

        $client->waitFor('body');
        self::assertStringContainsString('/articles', $client->getCurrentURL());
        self::assertSelectorExists('body');
        $this->assertPageHasBuiltAssets($client, 'assets/app.js', 'assets/entries/public-listing.js');
        $this->assertNoBrowserSevereErrors($client);
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
