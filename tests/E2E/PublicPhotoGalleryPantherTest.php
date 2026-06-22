<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverWait;

final class PublicPhotoGalleryPantherTest extends PantherTestCase
{
    public function testPhotoGalleryOpensRequestedSlideNavigatesByKeyboardAndRestoresFocus(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $client->request('GET', '/visites-de-ville/visiter-collioure-a-pied');
        $client->waitFor('.journey-gallery [data-gallery-index="1"]');

        $webDriver = $client->getWebDriver();
        $trigger = $webDriver->findElement(WebDriverBy::cssSelector('.journey-gallery [data-gallery-index="1"]'));
        $modalSelector = (string) $trigger->getAttribute('data-gallery-target');
        self::assertStringStartsWith('#', $modalSelector);

        $slideCount = $webDriver->findElements(WebDriverBy::cssSelector($modalSelector.' .js-gallery-slide'));
        self::assertCount(2, $slideCount);

        $trigger->click();
        $this->waitForGalleryIndex($webDriver, $modalSelector, 1);

        self::assertSame('false', $webDriver->findElement(WebDriverBy::cssSelector($modalSelector))->getAttribute('aria-hidden'));
        self::assertSame('2 / 2', trim($webDriver->findElement(WebDriverBy::cssSelector($modalSelector.' .js-gallery-counter'))->getText()));
        self::assertTrue((bool) $webDriver->executeScript(
            'return document.activeElement === document.querySelector(arguments[0] + " .js-gallery-close");',
            [$modalSelector]
        ));

        $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ARROW_RIGHT);
        $this->waitForGalleryIndex($webDriver, $modalSelector, 0);
        self::assertSame('1 / 2', trim($webDriver->findElement(WebDriverBy::cssSelector($modalSelector.' .js-gallery-counter'))->getText()));

        $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ARROW_LEFT);
        $this->waitForGalleryIndex($webDriver, $modalSelector, 1);
        self::assertSame('2 / 2', trim($webDriver->findElement(WebDriverBy::cssSelector($modalSelector.' .js-gallery-counter'))->getText()));

        $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'const modal = document.querySelector(arguments[0]); return modal?.hidden === true && modal?.getAttribute("aria-hidden") === "true";',
            [$modalSelector]
        ));

        self::assertTrue((bool) $webDriver->executeScript(
            'return document.activeElement === arguments[0];',
            [$trigger]
        ));
    }

    private function waitForGalleryIndex(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
        string $modalSelector,
        int $expectedIndex,
    ): void {
        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const slides = Array.from(document.querySelectorAll(arguments[0] + ' .js-gallery-slide'));

            return slides.findIndex((slide) => slide.classList.contains('is-active')) === arguments[1];
        JS, [$modalSelector, $expectedIndex]));
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
