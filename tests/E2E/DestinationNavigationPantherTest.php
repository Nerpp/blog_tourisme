<?php

namespace App\Tests\E2E;

use App\DataFixtures\UserFixtures;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverWait;

final class DestinationNavigationPantherTest extends PantherTestCase
{
    public function testDestinationNavigationIsResponsiveAndKeepsAdminActionProtected(): void
    {
        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        $webDriver->manage()->window()->setSize(new WebDriverDimension(390, 900));

        $client->request('GET', '/destinations/pyrenees-orientales');
        $client->waitFor('.destination-detail-page');

        self::assertSelectorExists('.destination-detail-breadcrumb [aria-current="page"]');
        self::assertSelectorExists('.destination-detail-search-path [aria-current="page"]');
        self::assertSelectorExists('.destination-detail-info [data-destination-discover-count]');
        self::assertSelectorNotExists('.destination-detail-badges');
        self::assertSelectorNotExists('.destination-detail-admin-link');
        self::assertTrue((bool) $webDriver->executeScript(
            'return document.documentElement.scrollWidth <= window.innerWidth;'
        ));

        $input = $webDriver->findElement(WebDriverBy::cssSelector('.js-destination-detail-search-input'));
        $input->sendKeys('Collioure');
        $webDriver->findElement(WebDriverBy::cssSelector('[data-destination-filter="destination"]'))->click();

        (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver): bool {
            $params = [];
            parse_str((string) parse_url($webDriver->getCurrentURL(), PHP_URL_QUERY), $params);

            return ($params['q'] ?? null) === 'Collioure' && ($params['type'] ?? null) === 'destination';
        });

        $rootParams = $this->queryParameters(
            $webDriver->findElement(WebDriverBy::cssSelector('[data-destination-search-path-root]'))->getAttribute('href'),
        );
        self::assertSame('Collioure', $rootParams['q'] ?? null);
        self::assertArrayNotHasKey('type', $rootParams);

        $resetButton = $webDriver->findElement(WebDriverBy::cssSelector('.js-destination-detail-search-reset'));
        self::assertTrue($resetButton->isDisplayed());
        $resetButton->click();
        (new WebDriverWait($webDriver, 8))->until(static fn (): bool => parse_url($webDriver->getCurrentURL(), PHP_URL_QUERY) === null);
        self::assertFalse($resetButton->isDisplayed());

        $client->request('GET', '/login');
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys(UserFixtures::ADMIN_EMAIL);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys(UserFixtures::ADMIN_PASSWORD);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();
        $client->waitFor('.logout-form');

        $client->request('GET', '/destinations/pyrenees-orientales');
        $client->waitFor('.destination-detail-admin-link');
        self::assertSelectorIsVisible('.destination-detail-admin-link');
        $this->assertNoBrowserSevereErrors($client);
    }

    /** @return array<string, mixed> */
    private function queryParameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        return $params;
    }
}
