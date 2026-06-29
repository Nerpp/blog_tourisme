<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;

final class PublicHikeMapPantherTest extends PantherTestCase
{
    public function testSeeThisPointOpensTheMatchingLeafletPopupWithoutExternalNavigation(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        $this->blockOpenStreetMapRequests($webDriver);

        $client->request('GET', '/randonnees/petite-boucle-de-montner');
        $client->waitFor('[data-public-hike-map]');
        self::assertNull($webDriver->executeScript(
            'return document.querySelector("[data-public-hike-map]").getAttribute("data-public-hike-map-ready");',
        ));
        self::assertFalse((bool) $webDriver->executeScript(
            'return performance.getEntriesByType("resource").some((entry) => entry.name.includes("public-hike-map-") || entry.name.includes("marker-shadow-"));',
        ));
        $this->assertPageHasBuiltAssets(
            $client,
            'assets/app.js',
            'assets/entries/public-detail.js',
            'assets/entries/related-articles.js',
        );
        $this->assertPageDoesNotHaveBuiltAssets($client, 'assets/entries/public-listing.js', 'assets/entries/comments.js');

        $trigger = $webDriver->findElement(WebDriverBy::cssSelector('[data-hike-map-focus][data-point-index="2"]'));
        $mapSelector = (string) $trigger->getAttribute('href');
        $pointId = (string) $trigger->getAttribute('data-point-id');

        self::assertStringStartsWith('#', $mapSelector);
        self::assertStringNotContainsString('google.com/maps', $mapSelector);
        self::assertNotSame('', $pointId);

        /** @var array{title: string, popupPosition: int, pointCount: int} $expected */
        $expected = $webDriver->executeScript(<<<'JS'
            const map = document.querySelector(arguments[0] + ' [data-public-hike-map]');
            const points = JSON.parse(map?.dataset.points || '[]');
            const index = points.findIndex((point) => String(point.id) === arguments[1]);

            return {
                title: index >= 0 ? String(points[index].title || '') : '',
                popupPosition: index + 1,
                pointCount: points.length,
            };
        JS, [$mapSelector, $pointId]);

        self::assertNotSame('', $expected['title']);
        self::assertGreaterThanOrEqual(2, $expected['pointCount']);

        $initialUrl = $webDriver->getCurrentURL();
        $initialWindowHandles = $webDriver->getWindowHandles();

        $trigger->click();

        $client->waitFor('[data-public-hike-map][data-public-hike-map-ready="true"]');
        self::assertTrue((bool) $webDriver->executeScript(
            'return performance.getEntriesByType("resource").some((entry) => entry.name.includes("public-hike-map-"));',
        ));
        self::assertSame(
            $expected['pointCount'],
            count($webDriver->findElements(WebDriverBy::cssSelector($mapSelector.' .leaflet-marker-icon')))
        );

        $popupSelector = $mapSelector.' .leaflet-popup-pane .leaflet-popup-content';
        (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $popupSelector, $expected): bool {
            $popups = $webDriver->findElements(WebDriverBy::cssSelector($popupSelector));

            return count($popups) === 1
                && $popups[0]->isDisplayed()
                && str_contains($popups[0]->getText(), $expected['title']);
        });

        $popupText = $webDriver->findElement(WebDriverBy::cssSelector($popupSelector))->getText();
        self::assertStringContainsString(
            sprintf('%d. %s', $expected['popupPosition'], $expected['title']),
            $popupText
        );
        self::assertSame($initialUrl, $webDriver->getCurrentURL());
        self::assertSame($initialWindowHandles, $webDriver->getWindowHandles());
        $this->assertNoBrowserSevereErrors($client);
    }

    private function blockOpenStreetMapRequests(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
    ): void {
        $devTools = new ChromeDevToolsDriver($webDriver);
        $devTools->execute('Network.enable');
        $devTools->execute('Network.setBlockedURLs', [
            'urls' => [
                '*://openstreetmap.org/*',
                '*://*.openstreetmap.org/*',
            ],
        ]);
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
