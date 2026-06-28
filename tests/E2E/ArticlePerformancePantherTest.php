<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverWait;

final class ArticlePerformancePantherTest extends PantherTestCase
{
    private const ARTICLE_PATH = '/articles/que-faire-a-collioure-en-une-journee';

    public function testDesktopArticleIsCenteredAndNeverRequestsHomepageHero(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $client = self::createBrowser();
        $driver = $client->getWebDriver();
        (new ChromeDevToolsDriver($driver))->execute('Emulation.clearDeviceMetricsOverride');
        $driver->manage()->window()->setSize(new WebDriverDimension(1440, 1000));

        $client->request('GET', self::ARTICLE_PATH);
        $client->waitFor('.article-show-main .article-content');

        /** @var array<string, float|int|bool> $layout */
        $layout = $driver->executeScript(<<<'JS'
            const container = document.querySelector('.public-detail-content .public-detail-container').getBoundingClientRect();
            const layout = document.querySelector('.article-show-layout').getBoundingClientRect();
            const main = document.querySelector('.article-show-main').getBoundingClientRect();
            const content = document.querySelector('.article-show-main .article-content').getBoundingClientRect();
            const cover = document.querySelector('.article-show-cover').getBoundingClientRect();
            const sidebar = document.querySelector('.article-show-sidebar').getBoundingClientRect();

            return {
                layoutWidth: layout.width,
                mainWidth: main.width,
                contentWidth: content.width,
                coverWidth: document.querySelector('.article-show-cover').offsetWidth,
                layoutCenterDelta: Math.abs((layout.left + layout.width / 2) - (container.left + container.width / 2)),
                contentCenterDelta: Math.abs((content.left + content.width / 2) - (main.left + main.width / 2)),
                sidebarAfterMain: sidebar.top >= main.bottom,
                homepageHeroRequested: performance.getEntriesByType('resource')
                    .some((entry) => entry.name.includes('hero-sea-mountain-desktop.webp')),
            };
        JS);

        self::assertEqualsWithDelta(1040.0, (float) $layout['layoutWidth'], 1.0);
        self::assertEqualsWithDelta(1040.0, (float) $layout['mainWidth'], 1.0);
        self::assertEqualsWithDelta(820.0, (float) $layout['contentWidth'], 1.0);
        self::assertEqualsWithDelta(560.0, (float) $layout['coverWidth'], 1.0);
        self::assertLessThanOrEqual(1.0, (float) $layout['layoutCenterDelta']);
        self::assertLessThanOrEqual(1.0, (float) $layout['contentCenterDelta']);
        self::assertTrue((bool) $layout['sidebarAfterMain']);
        self::assertFalse((bool) $layout['homepageHeroRequested']);
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testMobileCoverUsesCompactCandidateAndGallerySourceLoadsOnlyAfterClick(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $client = self::createBrowser();
        $driver = $client->getWebDriver();
        (new ChromeDevToolsDriver($driver))->execute('Emulation.setDeviceMetricsOverride', [
            'width' => 390,
            'height' => 844,
            'deviceScaleFactor' => 2,
            'mobile' => true,
        ]);

        $client->request('GET', self::ARTICLE_PATH);
        $client->waitFor('.article-show-cover img');
        $cover = $driver->findElement(WebDriverBy::cssSelector('.article-show-cover img'));

        /** @var array{width: float, viewport: int, overflow: bool, currentSrc: string, homepageHeroRequested: bool} $mobile */
        $mobile = (new WebDriverWait($driver, 8))->until(static function () use ($driver, $cover): array|false {
            $data = $driver->executeScript(<<<'JS'
                const image = arguments[0];
                const rect = image.getBoundingClientRect();
                return {
                    width: rect.width,
                    viewport: window.innerWidth,
                    overflow: document.documentElement.scrollWidth > window.innerWidth,
                    currentSrc: image.currentSrc || '',
                    homepageHeroRequested: performance.getEntriesByType('resource')
                        .some((entry) => entry.name.includes('hero-sea-mountain-desktop.webp')),
                };
            JS, [$cover]);

            return is_array($data) && ($data['currentSrc'] ?? '') !== '' ? $data : false;
        });

        self::assertEqualsWithDelta(364.0, $mobile['width'], 1.0);
        self::assertSame(390, $mobile['viewport']);
        self::assertFalse($mobile['overflow']);
        self::assertStringContainsString('_mobile.webp', $mobile['currentSrc']);
        self::assertFalse($mobile['homepageHeroRequested']);

        $client->waitFor('.article-gallery-section .journey-gallery-card');
        $trigger = $driver->findElement(WebDriverBy::cssSelector('.article-gallery-section .journey-gallery-card'));
        $modalSelector = (string) $trigger->getAttribute('data-gallery-target');
        $modalImageSelector = $modalSelector.' img[data-gallery-src]';
        $source = (string) $driver->findElement(WebDriverBy::cssSelector($modalImageSelector))->getAttribute('data-gallery-src');

        self::assertNotSame('', $source);
        self::assertNull($driver->executeScript('return document.querySelector(arguments[0]).getAttribute("src");', [$modalImageSelector]));
        self::assertFalse((bool) $driver->executeScript(
            'return performance.getEntriesByType("resource").some((entry) => new URL(entry.name).pathname === arguments[0]);',
            [$source],
        ));

        $trigger->click();
        (new WebDriverWait($driver, 8))->until(static fn () => (bool) $driver->executeScript(
            'const image = document.querySelector(arguments[0]); return image?.getAttribute("src") === arguments[1] && image.complete;',
            [$modalImageSelector, $source],
        ));

        self::assertSame($source, $driver->findElement(WebDriverBy::cssSelector($modalImageSelector))->getAttribute('src'));
        self::assertSame('false', $driver->findElement(WebDriverBy::cssSelector($modalSelector))->getAttribute('data-gallery-preload-neighbors'));
        $this->assertNoBrowserSevereErrors($client);
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
