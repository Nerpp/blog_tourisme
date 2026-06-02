<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;

final class PublicNavigationPantherTest extends PantherTestCase
{
    public function testPublicNavigationCanOpenArticlesPage(): void
    {
        $client = self::createBrowser();
        $client->request('GET', '/');

        self::assertSelectorTextContains('body', 'Blog Tourisme');

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.navbar-nav a[href="/articles"]'))->click();

        $client->waitFor('body');
        self::assertStringContainsString('/articles', $client->getCurrentURL());
        self::assertSelectorExists('body');
    }
}
