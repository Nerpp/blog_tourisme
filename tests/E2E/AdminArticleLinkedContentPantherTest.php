<?php

namespace App\Tests\E2E;

use App\DataFixtures\UserFixtures;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use Facebook\WebDriver\WebDriverWait;

final class AdminArticleLinkedContentPantherTest extends PantherTestCase
{
    public function testOnlySelectedArticleLinkedContentFieldsAreActive(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $client = self::createBrowser();
        $this->loginAsFixtureAdmin($client);
        $client->getWebDriver()->manage()->getLog('browser');
        $client->request('GET', '/admin/articles/new');
        $client->waitFor('[data-article-admin-form]');

        $webDriver = $client->getWebDriver();
        $typeSelect = new WebDriverSelect($webDriver->findElement(WebDriverBy::cssSelector('[data-article-link-type]')));
        $hikePanel = $webDriver->findElement(WebDriverBy::cssSelector('[data-article-link-panel="hike"]'));
        $cityVisitPanel = $webDriver->findElement(WebDriverBy::cssSelector('[data-article-link-panel="city_visit"]'));
        $roleField = $webDriver->findElement(WebDriverBy::cssSelector('[data-article-link-role]'));
        $hikeValue = $webDriver->findElement(WebDriverBy::name('linkedHike'));
        $cityVisitValue = $webDriver->findElement(WebDriverBy::name('linkedCityVisit'));

        self::assertFalse($hikePanel->isDisplayed());
        self::assertFalse($cityVisitPanel->isDisplayed());
        self::assertFalse($roleField->isDisplayed());
        self::assertFalse($hikeValue->isEnabled());
        self::assertFalse($cityVisitValue->isEnabled());

        $typeSelect->selectByValue('hike');
        (new WebDriverWait($webDriver, 5))->until(static fn (): bool => $hikePanel->isDisplayed());
        self::assertFalse($cityVisitPanel->isDisplayed());
        self::assertTrue($roleField->isDisplayed());
        self::assertTrue($hikeValue->isEnabled());
        self::assertFalse($cityVisitValue->isEnabled());

        $webDriver->executeScript("arguments[0].value = '123';", [$hikeValue]);
        $typeSelect->selectByValue('city_visit');
        (new WebDriverWait($webDriver, 5))->until(static fn (): bool => $cityVisitPanel->isDisplayed());
        self::assertFalse($hikePanel->isDisplayed());
        self::assertSame('', $hikeValue->getAttribute('value'));
        self::assertFalse($hikeValue->isEnabled());
        self::assertTrue($cityVisitValue->isEnabled());

        $webDriver->executeScript("arguments[0].value = '456';", [$cityVisitValue]);
        $typeSelect->selectByValue('hike');
        (new WebDriverWait($webDriver, 5))->until(static fn (): bool => $hikePanel->isDisplayed());
        self::assertSame('', $cityVisitValue->getAttribute('value'));
        self::assertFalse($cityVisitValue->isEnabled());

        $typeSelect->selectByValue('none');
        (new WebDriverWait($webDriver, 5))->until(static fn (): bool => !$hikePanel->isDisplayed() && !$cityVisitPanel->isDisplayed());
        self::assertFalse($roleField->isDisplayed());
        self::assertFalse($hikeValue->isEnabled());
        self::assertFalse($cityVisitValue->isEnabled());
        $this->assertNoBrowserSevereErrors($client);
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
