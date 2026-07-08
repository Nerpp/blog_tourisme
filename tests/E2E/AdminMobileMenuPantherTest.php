<?php

namespace App\Tests\E2E;

use App\DataFixtures\UserFixtures;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverWait;

final class AdminMobileMenuPantherTest extends PantherTestCase
{
    public function testAdminMobileMenuOpensClosesWithEscapeAndClosesAfterNavigation(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        $webDriver->manage()->window()->setSize(new WebDriverDimension(780, 900));

        $this->loginAsFixtureAdmin($client);
        $client->request('GET', '/admin');

        $client->waitFor('.js-admin-menu-toggle');
        self::assertSelectorIsVisible('.js-admin-menu-toggle');
        self::assertSelectorExists('.js-admin-menu-toggle[aria-expanded="false"]');
        self::assertSelectorNotExists('.js-admin-menu.is-open');
        self::assertFalse($webDriver->findElement(WebDriverBy::cssSelector('.js-admin-menu'))->isDisplayed());

        $webDriver->findElement(WebDriverBy::cssSelector('.js-admin-menu-toggle'))->click();
        $client->waitFor('.js-admin-menu.is-open');

        self::assertSelectorExists('.js-admin-menu-toggle[aria-expanded="true"]');
        self::assertTrue($webDriver->findElement(WebDriverBy::cssSelector('.js-admin-menu'))->isDisplayed());

        $webDriver->findElement(WebDriverBy::tagName('body'))->sendKeys(WebDriverKeys::ESCAPE);
        $client->waitFor('.js-admin-menu-toggle[aria-expanded="false"]');

        self::assertSelectorNotExists('.js-admin-menu.is-open');
        self::assertFalse($webDriver->findElement(WebDriverBy::cssSelector('.js-admin-menu'))->isDisplayed());

        $webDriver->findElement(WebDriverBy::cssSelector('.js-admin-menu-toggle'))->click();
        $client->waitFor('.js-admin-menu.is-open');
        $webDriver->findElement(WebDriverBy::cssSelector('.js-admin-menu a[href="/admin/articles"]'))->click();

        (new WebDriverWait($webDriver, 8))->until(
            static fn () => str_contains($webDriver->getCurrentURL(), '/admin/articles')
        );

        self::assertSelectorExists('.js-admin-menu-toggle[aria-expanded="false"]');
        self::assertSelectorNotExists('.js-admin-menu.is-open');
    }

    private function loginAsFixtureAdmin(\Symfony\Component\Panther\Client $client): void
    {
        $client->request('GET', '/login');

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys(UserFixtures::ADMIN_EMAIL);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys(UserFixtures::ADMIN_PASSWORD);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.logout-form');
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
