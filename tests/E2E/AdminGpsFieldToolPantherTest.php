<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;

final class AdminGpsFieldToolPantherTest extends PantherTestCase
{
    public function testAdminCanOpenGpsFieldToolThroughBrowser(): void
    {
        $email = $this->uniqueEmail('admin-gps');
        $password = 'E2E Admin GPS 2026 9!';
        $this->createVerifiedUser($email, $password, ['ROLE_ADMIN', 'ROLE_USER']);

        $client = self::createBrowser();
        $client->request('GET', '/login');

        self::assertSelectorIsVisible('form.login-form');

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys($email);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys($password);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.logout-form');
        $client->request('GET', '/admin/outils-terrain/gps');

        self::assertSelectorTextContains('h1', 'GPS terrain');
        self::assertSelectorExists('[data-high-precision-gps]');
        self::assertSelectorExists('[data-gps-latitude]');
        self::assertSelectorExists('[data-gps-longitude]');
        self::assertSelectorExists('[data-gps-accuracy]');
        self::assertSelectorTextContains('[data-gps-start]', 'GPS haute précision');
    }
}
