<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;

final class FooterNavigationPantherTest extends PantherTestCase
{
    public function testFooterIsResponsiveKeyboardAccessibleAndLinksToSiteMap(): void
    {
        $client = self::createBrowser();
        $driver = $client->getWebDriver();
        $devTools = new ChromeDevToolsDriver($driver);

        foreach ([
            ['width' => 1440, 'height' => 1000, 'mobile' => false],
            ['width' => 390, 'height' => 844, 'mobile' => true],
        ] as $viewport) {
            $devTools->execute('Emulation.setDeviceMetricsOverride', [
                'width' => $viewport['width'],
                'height' => $viewport['height'],
                'deviceScaleFactor' => 1,
                'mobile' => $viewport['mobile'],
            ]);
            $client->request('GET', '/');
            $client->waitFor('footer.site-footer');

            /** @var array{viewport: int, scrollWidth: int, footerLeft: float, footerRight: float, footerWidth: float, youtubeOutlineWidth: float, youtubeIconColor: string} $layout */
            $layout = $driver->executeScript(<<<'JS'
                const footer = document.querySelector('footer.site-footer');
                const youtube = footer.querySelector('a[href="https://www.youtube.com/channel/UCKv62tsRzbWy_rfm6_oKM-A"]');
                const youtubeIcon = youtube.querySelector('.site-footer__social-icon--youtube');
                youtube.focus();
                const footerRect = footer.getBoundingClientRect();
                const youtubeStyle = getComputedStyle(youtube);
                const youtubeIconStyle = getComputedStyle(youtubeIcon);

                return {
                    viewport: window.innerWidth,
                    scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                    footerLeft: footerRect.left,
                    footerRight: footerRect.right,
                    footerWidth: footerRect.width,
                    youtubeOutlineWidth: parseFloat(youtubeStyle.outlineWidth),
                    youtubeIconColor: youtubeIconStyle.color,
                };
            JS);

            self::assertSame($viewport['width'], $layout['viewport']);
            self::assertLessThanOrEqual($layout['viewport'] + 1, $layout['scrollWidth']);
            self::assertGreaterThanOrEqual(0, $layout['footerLeft']);
            self::assertLessThanOrEqual($layout['viewport'], $layout['footerRight']);
            self::assertLessThanOrEqual($layout['viewport'], $layout['footerWidth']);
            self::assertGreaterThanOrEqual(3.0, $layout['youtubeOutlineWidth']);
            self::assertSame('rgb(255, 0, 0)', $layout['youtubeIconColor']);
        }

        $siteMapLink = $driver->findElement(WebDriverBy::cssSelector('footer.site-footer a[href="/plan-du-site"]'));
        $siteMapLink->click();
        $client->waitFor('main.site-map-page');

        self::assertStringEndsWith('/plan-du-site', $client->getCurrentURL());
        self::assertSelectorTextContains('h1', 'Plan du site');
        $this->assertNoBrowserSevereErrors($client);
    }
}
