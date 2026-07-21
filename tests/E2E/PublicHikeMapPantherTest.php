<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverWait;

final class PublicHikeMapPantherTest extends PantherTestCase
{
    public function testPublicNavigationStaysAboveLeafletLayersAcrossResponsiveLayouts(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        $this->blockOpenStreetMapRequests($webDriver);

        foreach ([
            'desktop' => ['width' => 1280, 'height' => 900, 'openMenu' => false],
            'tablet' => ['width' => 768, 'height' => 900, 'openMenu' => true],
            'mobile' => ['width' => 390, 'height' => 844, 'openMenu' => true],
        ] as $viewport => $configuration) {
            $webDriver->manage()->window()->setSize(new WebDriverDimension(
                $configuration['width'],
                $configuration['height'],
            ));
            $client->request('GET', '/randonnees/petite-boucle-de-montner');

            $client->waitFor('[data-hike-map-focus][data-point-index="2"]');
            $webDriver
                ->findElement(WebDriverBy::cssSelector('[data-hike-map-focus][data-point-index="2"]'))
                ->click();
            $client->waitFor('[data-public-hike-map][data-public-hike-map-ready="true"]');
            $client->waitFor('.leaflet-popup-pane .leaflet-popup-content');

            if ($configuration['openMenu']) {
                self::assertFalse($webDriver->findElement(WebDriverBy::cssSelector('.js-navbar-collapse'))->isDisplayed());
                $this->assertLeafletLayersStayBelowNavigation($webDriver, $viewport.' menu closed', false);

                $webDriver->findElement(WebDriverBy::cssSelector('.js-navbar-toggler'))->click();
                $client->waitFor('.js-navbar-collapse.is-open');
                self::assertTrue($webDriver->findElement(WebDriverBy::cssSelector('.js-navbar-collapse'))->isDisplayed());
                $this->assertLeafletLayersStayBelowNavigation($webDriver, $viewport.' menu open', true);
            } else {
                self::assertTrue($webDriver->findElement(WebDriverBy::cssSelector('.js-navbar-collapse'))->isDisplayed());
                self::assertFalse($webDriver->findElement(WebDriverBy::cssSelector('.js-navbar-toggler'))->isDisplayed());

                $this->assertLeafletLayersStayBelowNavigation($webDriver, $viewport.' menu', false);
            }
        }

        $this->assertNoBrowserSevereErrors($client);
    }

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
        $this->assertPageHasBuiltStyles(
            $client,
            'assets/app.js',
            'assets/entries/public-detail.js',
            'assets/entries/related-articles.js',
        );
        $this->assertPageHasBuiltScripts($client, 'assets/app.js', 'assets/entries/public-detail.js');
        $this->assertPageDoesNotHaveBuiltScripts($client, 'assets/entries/related-articles.js');
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

    private function assertLeafletLayersStayBelowNavigation(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
        string $viewportState,
        bool $menuIsOpen,
    ): void {
        foreach ([
            'control' => '.leaflet-control-zoom-in',
            'marker' => '.leaflet-marker-icon',
            'popup' => '.leaflet-popup-pane .leaflet-popup-content',
        ] as $layer => $selector) {
            $stack = $this->overlapWithPublicNavigation($webDriver, $selector, $menuIsOpen);

            self::assertTrue(
                $stack['overlap'],
                sprintf('%s: the %s must overlap the navigation during the check.', $viewportState, $layer),
            );
            self::assertGreaterThanOrEqual(
                0,
                $stack['navigationIndex'],
                sprintf('%s: navigation must be present in the painted stack.', $viewportState),
            );
            self::assertGreaterThanOrEqual(
                0,
                $stack['layerIndex'],
                sprintf(
                    '%s: the %s must be present in the painted stack (%s).',
                    $viewportState,
                    $layer,
                    json_encode($stack),
                ),
            );
            self::assertLessThan(
                $stack['layerIndex'],
                $stack['navigationIndex'],
                sprintf('%s: public navigation must be painted above the Leaflet %s.', $viewportState, $layer),
            );
        }
    }

    /**
     * @return array{overlap: bool, navigationIndex: int, layerIndex: int, painted: list<string>}
     */
    private function overlapWithPublicNavigation(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
        string $layerSelector,
        bool $menuIsOpen,
    ): array {
        /** @var array{overlap: bool, navigationIndex: int, layerIndex: int, painted: list<string>} $stack */
        $stack = $webDriver->executeScript(<<<'JS'
            const mapCanvas = document.querySelector('[data-public-hike-map]');
            const mapRect = mapCanvas?.getBoundingClientRect();
            const layer = [...document.querySelectorAll(arguments[0])].find((candidate) => {
                if (!mapRect) {
                    return false;
                }

                const candidateRect = candidate.getBoundingClientRect();
                const candidateCenterX = candidateRect.left + (candidateRect.width / 2);
                const candidateCenterY = candidateRect.top + (candidateRect.height / 2);

                return candidateRect.width > 0
                    && candidateRect.height > 0
                    && candidateCenterX >= mapRect.left
                    && candidateCenterX <= mapRect.right
                    && candidateCenterY >= mapRect.top
                    && candidateCenterY <= mapRect.bottom;
            });
            const navigation = document.querySelector(arguments[1] ? '.js-navbar-collapse' : '.site-header');
            const header = document.querySelector('.site-header');

            if (!layer || !navigation || !header) {
                return { overlap: false, navigationIndex: -1, layerIndex: -1, painted: [] };
            }

            const navigationRect = navigation.getBoundingClientRect();
            let layerRect = layer.getBoundingClientRect();
            const targetY = navigationRect.top + (navigationRect.height / 2);
            const layerCenterY = layerRect.top + (layerRect.height / 2);

            window.scrollBy({ top: layerCenterY - targetY, behavior: 'instant' });
            layerRect = layer.getBoundingClientRect();

            const x = layerRect.left + (layerRect.width / 2);
            const y = layerRect.top + (layerRect.height / 2);
            const paintedElements = document.elementsFromPoint(x, y);
            const navigationIndex = paintedElements.findIndex((element) => element === header || header.contains(element));
            const layerIndex = paintedElements.findIndex((element) => element === layer || layer.contains(element));
            const currentNavigationRect = navigation.getBoundingClientRect();
            const overlap = x >= currentNavigationRect.left
                && x <= currentNavigationRect.right
                && y >= currentNavigationRect.top
                && y <= currentNavigationRect.bottom;

            return {
                overlap,
                navigationIndex,
                layerIndex,
                painted: paintedElements.map((element) => `${element.tagName}.${element.className || ''}`),
            };
        JS, [$layerSelector, $menuIsOpen]);

        return $stack;
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
