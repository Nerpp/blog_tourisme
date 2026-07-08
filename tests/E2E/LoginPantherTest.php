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

        try {
            self::assertSelectorIsVisible('form.login-form');
        } catch (\Throwable $exception) {
            $html = $client->getPageSource();
            $url = $client->getCurrentURL();
            $title = $client->getTitle();
            $status = $client->getWebDriver()->executeScript(<<<'JS'
                const [navigation] = performance.getEntriesByType('navigation');
                return navigation && 'responseStatus' in navigation ? navigation.responseStatus : null;
                JS);

            file_put_contents('/tmp/panther-login-failure.html', $html);
            $client->takeScreenshot('/tmp/panther-login-failure.png');

            $excerpt = substr((string) preg_replace('/\s+/', ' ', $html), 0, 2000);
            fwrite(STDERR, sprintf(
                "\nPanther login failure diagnostics:\nURL: %s\nTitle: %s\nHTTP status: %s\nHTML excerpt: %s\n",
                $url,
                $title,
                is_int($status) && $status > 0 ? (string) $status : 'unavailable',
                $excerpt,
            ));

            throw $exception;
        }

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
