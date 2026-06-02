<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;

final class LoginPantherTest extends PantherTestCase
{
    public function testUserCanLoginAndLogoutThroughBrowser(): void
    {
        $email = $this->uniqueEmail('login');
        $password = 'E2E Login Password 2026 9!';
        $this->createVerifiedUser($email, $password);

        $client = self::createBrowser();
        $client->request('GET', '/login');

        self::assertSelectorIsVisible('form.login-form');

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys($email);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys($password);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.logout-form');
        self::assertSelectorExists('.logout-form');
        self::assertStringContainsString('/', $client->getCurrentURL());

        $webDriver->findElement(WebDriverBy::cssSelector('.logout-form button[type="submit"]'))->click();

        $client->waitFor('.nav-auth-link--login');
        self::assertSelectorExists('.nav-auth-link--login');
    }
}
