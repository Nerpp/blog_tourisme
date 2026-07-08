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
