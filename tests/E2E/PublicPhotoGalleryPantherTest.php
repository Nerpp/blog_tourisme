<?php

namespace App\Tests\E2E;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\MediaAsset;
use App\Enum\MediaRole;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
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

    public function testExternalVideoSourceIsLoadedOnlyWhileGalleryIsOpen(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $slug = $this->ensureFixtureVideoIsLinkedToCityVisit();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        $this->blockExternalMediaRequests($webDriver);

        $client->request('GET', '/visites-de-ville/'.$slug);
        $client->waitFor('.video-gallery-card[data-gallery-index="0"]');

        $trigger = $webDriver->findElement(WebDriverBy::cssSelector('.video-gallery-card[data-gallery-index="0"]'));
        $modalSelector = (string) $trigger->getAttribute('data-gallery-target');
        self::assertStringStartsWith('#', $modalSelector);

        $iframeSelector = $modalSelector.' iframe[data-video-src]';
        $iframe = $webDriver->findElement(WebDriverBy::cssSelector($iframeSelector));
        self::assertNull($webDriver->executeScript(
            'return document.querySelector(arguments[0])?.getAttribute("src");',
            [$iframeSelector]
        ));

        $initialUrl = $webDriver->getCurrentURL();
        $initialWindowHandles = $webDriver->getWindowHandles();

        $trigger->click();

        /** @var string $openedSource */
        $openedSource = (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $iframeSelector): string|false {
            $source = $webDriver->executeScript(
                'return document.querySelector(arguments[0])?.getAttribute("src") || "";',
                [$iframeSelector]
            );

            return is_string($source) && $source !== '' ? $source : false;
        });

        self::assertStringContainsString('autoplay=1', $openedSource);
        self::assertSame('false', $webDriver->findElement(WebDriverBy::cssSelector($modalSelector))->getAttribute('aria-hidden'));
        self::assertSame($initialWindowHandles, $webDriver->getWindowHandles());

        $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'const modal = document.querySelector(arguments[0]); const iframe = document.querySelector(arguments[1]); return modal?.hidden === true && iframe?.getAttribute("src") === null;',
            [$modalSelector, $iframeSelector]
        ));

        self::assertNull($iframe->getAttribute('src'));
        self::assertSame($initialUrl, $webDriver->getCurrentURL());
        self::assertSame($initialWindowHandles, $webDriver->getWindowHandles());
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

    private function ensureFixtureVideoIsLinkedToCityVisit(): string
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $cityVisit = $entityManager->getRepository(CityVisitDraft::class)->findOneBy([
            'slug' => 'visiter-collioure-a-pied',
        ]);
        $video = $entityManager->getRepository(MediaAsset::class)->findOneBy([
            'externalUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        self::assertInstanceOf(CityVisitDraft::class, $cityVisit);
        self::assertInstanceOf(MediaAsset::class, $video);

        $existingLink = $entityManager->getRepository(CityVisitDraftMedia::class)->findOneBy([
            'cityVisitDraft' => $cityVisit,
            'mediaAsset' => $video,
        ]);

        if (!$existingLink instanceof CityVisitDraftMedia) {
            $entityManager->persist((new CityVisitDraftMedia())
                ->setCityVisitDraft($cityVisit)
                ->setMediaAsset($video)
                ->setRole(MediaRole::Gallery)
                ->setPosition(2));
            $entityManager->flush();
        }

        $slug = $cityVisit->getSlug();
        self::assertNotNull($slug);
        self::ensureKernelShutdown();

        return $slug;
    }

    private function blockExternalMediaRequests(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
    ): void {
        $devTools = new ChromeDevToolsDriver($webDriver);
        $devTools->execute('Network.enable');
        $devTools->execute('Network.setBlockedURLs', [
            'urls' => [
                '*://youtube.com/*',
                '*://*.youtube.com/*',
                '*://youtube-nocookie.com/*',
                '*://*.youtube-nocookie.com/*',
                '*://*.googlevideo.com/*',
                '*://img.youtube.com/*',
                '*://*.ytimg.com/*',
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
