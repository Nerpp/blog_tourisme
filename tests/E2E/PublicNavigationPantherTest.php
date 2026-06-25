<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;

final class PublicNavigationPantherTest extends PantherTestCase
{
    public function testPublicNavigationCanOpenArticlesPage(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $client->request('GET', '/');

        self::assertSelectorTextContains('body', 'Blog Tourisme');
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
