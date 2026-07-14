<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverWait;

final class PublicDetailCoverPantherTest extends PantherTestCase
{
    /**
     * @var array<string, array{path: string, selector: string, objectFit: string|null}>
     */
    private const PAGES = [
        'article' => [
            'path' => '/articles/que-faire-a-collioure-en-une-journee',
            'selector' => 'img.article-show-cover__image',
            'objectFit' => null,
        ],
        'visit' => [
            'path' => '/visites-de-ville/visiter-collioure-a-pied',
            'selector' => '.public-detail-cover--media img.public-detail-cover__image',
            'objectFit' => 'cover',
        ],
        'hike' => [
            'path' => '/randonnees/boucle-du-canigou-decouverte',
            'selector' => '.public-detail-cover--media img.public-detail-cover__image',
            'objectFit' => 'cover',
        ],
    ];

    public function testPublicCoversLoadResponsiveWebpWithoutLazyLoadingOnDesktopAndMobile(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $client = self::createBrowser();
        $driver = $client->getWebDriver();
        $devTools = new ChromeDevToolsDriver($driver);

        $devTools->execute('Emulation.clearDeviceMetricsOverride');
        $driver->manage()->window()->setSize(new WebDriverDimension(1440, 1000));
        foreach (self::PAGES as $kind => $page) {
            $client->request('GET', $page['path']);
            $cover = $this->coverData($driver, $page['selector']);

            self::assertStringEndsWith('.webp', $cover['currentSrc'], $kind);
            self::assertSame('eager', $cover['loading'], $kind);
            self::assertSame('async', $cover['decoding'], $kind);
            self::assertSame('high', $cover['fetchPriority'], $kind);
            self::assertGreaterThan(0, $cover['declaredWidth'], $kind);
            self::assertGreaterThan(0, $cover['declaredHeight'], $kind);
            self::assertGreaterThan(0.0, $cover['renderedWidth'], $kind);
            self::assertGreaterThan(0.0, $cover['renderedHeight'], $kind);
            self::assertFalse($cover['overflow'], $kind);
            self::assertTrue($cover['insidePicture'], $kind);
            if ($page['objectFit'] !== null) {
                self::assertSame($page['objectFit'], $cover['objectFit'], $kind);
            }
        }

        $devTools->execute('Emulation.setDeviceMetricsOverride', [
            'width' => 390,
            'height' => 844,
            'deviceScaleFactor' => 2,
            'mobile' => true,
        ]);
        foreach (self::PAGES as $kind => $page) {
            $client->request('GET', $page['path']);
            $cover = $this->coverData($driver, $page['selector']);

            self::assertStringEndsWith('.webp', $cover['currentSrc'], $kind);
            self::assertStringNotContainsString('_large.', $cover['currentSrc'], $kind);
            self::assertSame(390, $cover['viewportWidth'], $kind);
            self::assertLessThanOrEqual(390.0, $cover['renderedWidth'], $kind);
            self::assertFalse($cover['overflow'], $kind);
        }
    }

    public function testSharedHeroUsesAFullWidthTopPanelAndATiltedBottomOverlappingCoverWithoutHorizontalOverflow(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $client = self::createBrowser();
        $driver = $client->getWebDriver();
        $devTools = new ChromeDevToolsDriver($driver);

        foreach ([1440, 1280, 1024, 768, 390] as $viewportWidth) {
            $viewportHeight = $viewportWidth === 390 ? 844 : 1000;
            $devTools->execute('Emulation.setDeviceMetricsOverride', [
                'width' => $viewportWidth,
                'height' => $viewportHeight,
                'deviceScaleFactor' => 1,
                'mobile' => $viewportWidth === 390,
            ]);

            foreach (self::PAGES as $kind => $page) {
                $client->request('GET', $page['path']);

                /** @var array<string, float|int|string|bool> $layout */
                $layout = (new WebDriverWait($driver, 8))->until(static function () use ($driver): array|false {
                    $result = $driver->executeScript(<<<'JS'
                        const hero = document.querySelector('.public-detail-hero');
                        const grid = hero?.querySelector('.public-detail-hero-grid');
                        const content = grid?.querySelector('.public-detail-hero__content');
                        const cover = grid?.querySelector('.public-detail-cover');

                        if (!hero || !grid || !content || !cover) {
                            return null;
                        }

                        const gridRect = grid.getBoundingClientRect();
                        const heroRect = hero.getBoundingClientRect();
                        const contentRect = content.getBoundingClientRect();
                        const coverRect = cover.getBoundingClientRect();
                        const panelStyle = getComputedStyle(hero, '::before');
                        const coverStyle = getComputedStyle(cover);
                        const panelWidth = parseFloat(panelStyle.width);
                        const panelHeight = parseFloat(panelStyle.height);
                        const coverTransform = coverStyle.transform;
                        const coverMatrix = new DOMMatrixReadOnly(coverTransform);

                        return {
                            viewportWidth: window.innerWidth,
                            documentScrollWidth: document.documentElement.scrollWidth,
                            heroWidth: heroRect.width,
                            heroHeight: heroRect.height,
                            heroTop: heroRect.top,
                            panelWidth,
                            panelHeight,
                            panelTop: heroRect.top + parseFloat(panelStyle.top),
                            panelBottom: heroRect.top + parseFloat(panelStyle.top) + panelHeight,
                            panelRight: heroRect.left + panelWidth,
                            gridWidth: gridRect.width,
                            gridRight: gridRect.right,
                            contentWidth: contentRect.width,
                            contentRight: contentRect.right,
                            coverWidth: coverRect.width,
                            coverHeight: coverRect.height,
                            coverLayoutWidth: parseFloat(coverStyle.width),
                            coverLayoutHeight: parseFloat(coverStyle.height),
                            coverLeft: coverRect.left,
                            coverBottom: coverRect.bottom,
                            coverRight: coverRect.right,
                            gridColumns: getComputedStyle(grid).gridTemplateColumns,
                            coverTransform,
                            coverRotation: Math.atan2(coverMatrix.b, coverMatrix.a) * (180 / Math.PI),
                            coverRadius: parseFloat(getComputedStyle(cover).borderTopLeftRadius),
                        };
                    JS);

                    return is_array($result) ? $result : false;
                });

                self::assertSame($viewportWidth, $layout['viewportWidth'], $kind);
                self::assertLessThanOrEqual($viewportWidth, $layout['documentScrollWidth'], $kind);
                self::assertGreaterThan(0, $layout['coverRadius'], $kind);
                self::assertEqualsWithDelta($layout['heroWidth'], $layout['panelWidth'], 1.0, $kind);
                self::assertEqualsWithDelta($layout['heroTop'], $layout['panelTop'], 1.0, $kind);
                self::assertGreaterThan($layout['panelBottom'], $layout['coverBottom'], $kind);

                if ($viewportWidth >= 981) {
                    self::assertLessThanOrEqual(1180, $layout['gridWidth'], $kind);
                    self::assertGreaterThanOrEqual(0.52, $layout['coverWidth'] / $layout['gridWidth'], $kind);
                    self::assertGreaterThanOrEqual(0.40, $layout['contentWidth'] / $layout['gridWidth'], $kind);
                    self::assertLessThanOrEqual(0.45, $layout['contentWidth'] / $layout['gridWidth'], $kind);
                    self::assertGreaterThanOrEqual(0.78, $layout['panelHeight'] / $layout['coverLayoutHeight'], $kind);
                    self::assertLessThanOrEqual(0.88, $layout['panelHeight'] / $layout['coverLayoutHeight'], $kind);
                    self::assertGreaterThanOrEqual(60.0, $layout['coverBottom'] - $layout['panelBottom'], $kind);
                    self::assertLessThanOrEqual(125.0, $layout['coverBottom'] - $layout['panelBottom'], $kind);
                    self::assertGreaterThanOrEqual(15.0, $layout['coverRight'] - $layout['gridRight'], $kind);
                    self::assertLessThanOrEqual(50.0, $layout['coverRight'] - $layout['gridRight'], $kind);
                    self::assertGreaterThan($layout['gridRight'], $layout['coverRight'], $kind);
                    self::assertNotSame('none', $layout['coverTransform'], $kind);
                    self::assertGreaterThanOrEqual(-2.0, $layout['coverRotation'], $kind);
                    self::assertLessThanOrEqual(-1.0, $layout['coverRotation'], $kind);
                } elseif ($viewportWidth > 640) {
                    self::assertEqualsWithDelta($layout['gridWidth'], $layout['coverLayoutWidth'], 1.0, $kind);
                    self::assertGreaterThanOrEqual(45.0, $layout['coverBottom'] - $layout['panelBottom'], $kind);
                    self::assertLessThanOrEqual(90.0, $layout['coverBottom'] - $layout['panelBottom'], $kind);
                    self::assertNotSame('none', $layout['coverTransform'], $kind);
                    self::assertGreaterThanOrEqual(-1.0, $layout['coverRotation'], $kind);
                    self::assertLessThanOrEqual(-0.4, $layout['coverRotation'], $kind);
                    self::assertStringNotContainsString(' ', trim((string) $layout['gridColumns']), $kind);
                } else {
                    self::assertEqualsWithDelta($layout['gridWidth'], $layout['coverWidth'], 1.0, $kind);
                    self::assertEqualsWithDelta(48.0, $layout['coverBottom'] - $layout['panelBottom'], 1.0, $kind);
                    self::assertSame('none', $layout['coverTransform'], $kind);
                    self::assertEqualsWithDelta(0.0, $layout['coverRotation'], 0.01, $kind);
                    self::assertStringNotContainsString(' ', trim((string) $layout['gridColumns']), $kind);
                }
            }
        }

        $this->assertNoBrowserSevereErrors($client);
    }

    /**
     * @return array{
     *     currentSrc: string,
     *     loading: string,
     *     decoding: string,
     *     fetchPriority: string,
     *     declaredWidth: int,
     *     declaredHeight: int,
     *     renderedWidth: float,
     *     renderedHeight: float,
     *     viewportWidth: int,
     *     overflow: bool,
     *     insidePicture: bool,
     *     objectFit: string
     * }
     */
    private function coverData(RemoteWebDriver $driver, string $selector): array
    {
        $image = (new WebDriverWait($driver, 8))->until(static function () use ($driver, $selector): object|false {
            $elements = $driver->findElements(WebDriverBy::cssSelector($selector));

            return $elements[0] ?? false;
        });

        /** @var array<string, mixed> $data */
        $data = (new WebDriverWait($driver, 8))->until(static function () use ($driver, $image): array|false {
            $result = $driver->executeScript(<<<'JS'
                const image = arguments[0];
                const rect = image.getBoundingClientRect();

                return {
                    currentSrc: image.currentSrc || '',
                    loading: image.getAttribute('loading') || '',
                    decoding: image.getAttribute('decoding') || '',
                    fetchPriority: image.getAttribute('fetchpriority') || '',
                    declaredWidth: Number(image.getAttribute('width') || 0),
                    declaredHeight: Number(image.getAttribute('height') || 0),
                    renderedWidth: rect.width,
                    renderedHeight: rect.height,
                    viewportWidth: window.innerWidth,
                    overflow: document.documentElement.scrollWidth > window.innerWidth,
                    insidePicture: image.parentElement?.tagName === 'PICTURE',
                    objectFit: getComputedStyle(image).objectFit,
                };
            JS, [$image]);

            return is_array($result) && is_string($result['currentSrc'] ?? null) && $result['currentSrc'] !== ''
                ? $result
                : false;
        });

        return [
            'currentSrc' => (string) $data['currentSrc'],
            'loading' => (string) $data['loading'],
            'decoding' => (string) $data['decoding'],
            'fetchPriority' => (string) $data['fetchPriority'],
            'declaredWidth' => (int) $data['declaredWidth'],
            'declaredHeight' => (int) $data['declaredHeight'],
            'renderedWidth' => (float) $data['renderedWidth'],
            'renderedHeight' => (float) $data['renderedHeight'],
            'viewportWidth' => (int) $data['viewportWidth'],
            'overflow' => (bool) $data['overflow'],
            'insidePicture' => (bool) $data['insidePicture'],
            'objectFit' => (string) $data['objectFit'],
        ];
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
