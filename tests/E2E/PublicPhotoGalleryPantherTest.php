<?php

namespace App\Tests\E2E;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\MediaAsset;
use App\Enum\MediaRole;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Media\MediaVariantService;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverWait;

final class PublicPhotoGalleryPantherTest extends PantherTestCase
{
    public function testHomepageStandardCardsKeepThumbWebpAtMobileAndDesktopWidths(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $this->ensureFixtureStandardGalleryVariants();
        $fixtureState = $this->prepareHomepageStandardFixturePhoto();

        try {
            $mobileClient = self::createBrowser();
            $mobileDriver = $mobileClient->getWebDriver();
            $this->disableBrowserCache($mobileDriver);
            (new ChromeDevToolsDriver($mobileDriver))->execute('Emulation.setDeviceMetricsOverride', [
                'width' => 390,
                'height' => 844,
                'deviceScaleFactor' => 2,
                'mobile' => true,
            ]);
            $mobileClient->request('GET', '/');
            $mobileClient->waitFor('.home-destination-card img.home-destination-card__img');
            $destinationMobile = $this->responsiveImageData(
                $mobileDriver,
                '.home-destination-card img.home-destination-card__img',
            );
            $latestMobile = $this->responsiveImageData(
                $mobileDriver,
                '.home-latest-card img.home-latest-card__image',
            );

            self::assertStringContainsString('_thumb.webp', $destinationMobile['currentSrc']);
            self::assertStringNotContainsString('_mobile.webp', $destinationMobile['currentSrc']);
            self::assertStringNotContainsString('_medium.webp', $destinationMobile['currentSrc']);
            self::assertStringNotContainsString('_large.webp', $destinationMobile['currentSrc']);
            self::assertSame('', $destinationMobile['srcset']);
            self::assertSame('', $destinationMobile['sizes']);
            self::assertSame('lazy', $destinationMobile['loading']);

            self::assertStringContainsString('_thumb.webp', $latestMobile['currentSrc']);
            self::assertStringNotContainsString('_mobile.webp', $latestMobile['currentSrc']);
            self::assertStringNotContainsString('_medium.webp', $latestMobile['currentSrc']);
            self::assertStringNotContainsString('_large.webp', $latestMobile['currentSrc']);
            self::assertSame('', $latestMobile['srcset']);
            self::assertSame('', $latestMobile['sizes']);
            self::assertSame('eager', $latestMobile['loading']);

            $desktopClient = self::createBrowser();
            $desktopDriver = $desktopClient->getWebDriver();
            $this->disableBrowserCache($desktopDriver);
            $desktopDriver->manage()->window()->setSize(new WebDriverDimension(1440, 1000));
            $desktopClient->request('GET', '/');
            $desktopClient->waitFor('.home-destination-card img.home-destination-card__img');
            $destinationDesktop = $this->responsiveImageData(
                $desktopDriver,
                '.home-destination-card img.home-destination-card__img',
            );
            $latestDesktop = $this->responsiveImageData(
                $desktopDriver,
                '.home-latest-card img.home-latest-card__image',
            );

            self::assertStringContainsString('_thumb.webp', $destinationDesktop['currentSrc']);
            self::assertStringContainsString('_thumb.webp', $latestDesktop['currentSrc']);
            self::assertGreaterThan(0, $destinationDesktop['width']);
            self::assertGreaterThan(0, $destinationDesktop['height']);
            self::assertGreaterThan(0, $latestDesktop['width']);
            self::assertGreaterThan(0, $latestDesktop['height']);
        } finally {
            $this->restoreHomepageStandardFixturePhoto($fixtureState);
        }
    }

    public function testGallerySelectsTheNewCompactDisplayVariants(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $this->ensureFixtureStandardGalleryVariants();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        (new ChromeDevToolsDriver($webDriver))->execute('Emulation.setDeviceMetricsOverride', [
            'width' => 390,
            'height' => 844,
            'deviceScaleFactor' => 2,
            'mobile' => true,
        ]);
        $client->request('GET', '/visites-de-ville/visiter-collioure-a-pied');
        $client->waitFor('.journey-gallery img[srcset]');

        $image = $webDriver->findElement(WebDriverBy::cssSelector('.journey-gallery img[srcset]'));
        $webDriver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$image]);

        /** @var string $currentSource */
        $currentSource = (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $image): string|false {
            $source = $webDriver->executeScript('return arguments[0].currentSrc || "";', [$image]);

            return is_string($source) && $source !== '' ? $source : false;
        });
        $srcset = (string) $image->getAttribute('srcset');

        self::assertStringContainsString(' 640w', $srcset);
        self::assertStringContainsString(' 768w', $srcset);
        self::assertStringContainsString(' 960w', $srcset);
        self::assertStringContainsString(' 1600w', $srcset);
        self::assertSame(1, substr_count($srcset, ' 1600w'));
        self::assertStringEndsWith('.webp', $currentSource);
        self::assertStringContainsString('_content640.webp', $currentSource);
        self::assertStringNotContainsString('_medium.webp', $currentSource);
        self::assertStringNotContainsString('_large.webp', $currentSource);

        $desktopClient = self::createBrowser();
        $desktopDriver = $desktopClient->getWebDriver();
        (new ChromeDevToolsDriver($desktopDriver))->execute('Emulation.setDeviceMetricsOverride', [
            'width' => 1440,
            'height' => 1000,
            'deviceScaleFactor' => 1,
            'mobile' => false,
        ]);
        $desktopClient->request('GET', '/visites-de-ville/visiter-collioure-a-pied');
        $desktopClient->waitFor('.journey-gallery img[srcset]');
        $desktopSource = $this->currentSourceForSelector($desktopDriver, '.journey-gallery img[srcset]');

        self::assertStringEndsWith('.webp', $desktopSource);
        self::assertStringContainsString('_content768.webp', $desktopSource);
    }

    public function testPanoramaBundleLoadsOnlyWhenTheImmersiveGalleryOpens(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $slug = $this->ensureFixturePanoramaIsLinkedToCityVisit();
        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();

        $client->request('GET', '/visites-de-ville/'.$slug);
        $client->waitFor('.immersive-gallery-card[data-gallery-index="0"]');

        self::assertFalse($this->resourceWasRequested($webDriver, 'panorama-viewer-'));
        $trigger = $webDriver->findElement(WebDriverBy::cssSelector('.immersive-gallery-card[data-gallery-index="0"]'));
        $modalSelector = (string) $trigger->getAttribute('data-gallery-target');
        $trigger->click();

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'return document.querySelector(arguments[0] + " .js-panorama-viewer")?.dataset.panoramaInitialized === "true";',
            [$modalSelector],
        ));

        self::assertTrue($this->resourceWasRequested($webDriver, 'panorama-viewer-'));
        self::assertSame('false', $webDriver->findElement(WebDriverBy::cssSelector($modalSelector))->getAttribute('aria-hidden'));
        $this->assertNoBrowserSevereErrors($client);
    }

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

    public function testVideoGalleryKeepsTheCaptionCompactAcrossBreakpoints(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $slug = $this->ensureFixtureVideoIsLinkedToCityVisit();
        $originalCaption = $this->replaceFixtureVideoCaption(str_repeat(
            'Une longue légende de test avec un lien-ininterrompu-exemple.test/video/aerienne. ',
            24,
        ));

        try {
            $client = self::createBrowser();
            $webDriver = $client->getWebDriver();
            $this->blockExternalMediaRequests($webDriver);

            foreach ([1440, 1280, 1024, 768, 390] as $viewportWidth) {
                $viewportHeight = $viewportWidth === 390 ? 844 : 900;
                (new ChromeDevToolsDriver($webDriver))->execute('Emulation.setDeviceMetricsOverride', [
                    'width' => $viewportWidth,
                    'height' => $viewportHeight,
                    'deviceScaleFactor' => 1,
                    'mobile' => $viewportWidth === 390,
                ]);
                $client->request('GET', '/visites-de-ville/'.$slug);
                $client->waitFor('.video-gallery-card[data-gallery-index="0"]');

                $trigger = $webDriver->findElement(WebDriverBy::cssSelector('.video-gallery-card[data-gallery-index="0"]'));
                $modalSelector = (string) $trigger->getAttribute('data-gallery-target');
                $trigger->click();
                $client->waitFor($modalSelector.' .gallery-modal__caption--video');

                /** @var array<string, float|int|string> $layout */
                $layout = $webDriver->executeScript(<<<'JS'
                    const modal = document.querySelector(arguments[0]);
                    const dialog = modal.querySelector('.gallery-modal__dialog--video');
                    const slide = modal.querySelector('.gallery-modal__slide--video.is-active');
                    const video = slide.querySelector('.gallery-modal__video-frame');
                    const caption = slide.querySelector('.gallery-modal__caption--video');
                    const previous = modal.querySelector('.gallery-modal__nav--prev');
                    const next = modal.querySelector('.gallery-modal__nav--next');
                    const close = modal.querySelector('.gallery-modal__close');
                    const footer = modal.querySelector('.gallery-modal__footer');
                    const rect = (element) => element.getBoundingClientRect();

                    return {
                        viewportWidth: window.innerWidth,
                        viewportHeight: window.innerHeight,
                        documentScrollWidth: document.documentElement.scrollWidth,
                        dialogWidth: rect(dialog).width,
                        slideWidth: rect(slide).width,
                        videoWidth: rect(video).width,
                        captionWidth: rect(caption).width,
                        captionClientHeight: caption.clientHeight,
                        captionScrollHeight: caption.scrollHeight,
                        gridTemplateColumns: getComputedStyle(slide).gridTemplateColumns,
                        previousLeft: rect(previous).left,
                        nextRight: rect(next).right,
                        closeRight: rect(close).right,
                        closeTop: rect(close).top,
                        footerBottom: rect(footer).bottom,
                    };
                JS, [$modalSelector]);

                self::assertLessThanOrEqual($viewportWidth, $layout['documentScrollWidth']);
                self::assertLessThanOrEqual($viewportWidth, $layout['dialogWidth']);
                self::assertGreaterThanOrEqual(0, $layout['previousLeft']);
                self::assertLessThanOrEqual($viewportWidth, $layout['nextRight']);
                self::assertLessThanOrEqual($viewportWidth, $layout['closeRight']);
                self::assertGreaterThanOrEqual(0, $layout['closeTop']);
                self::assertLessThanOrEqual($viewportHeight, $layout['footerBottom']);

                if ($viewportWidth >= 1024) {
                    self::assertLessThanOrEqual(320, $layout['captionWidth']);
                    self::assertGreaterThanOrEqual(0.70, $layout['videoWidth'] / $layout['slideWidth']);
                } elseif ($viewportWidth === 768) {
                    self::assertLessThanOrEqual(260, $layout['captionWidth']);
                    self::assertStringContainsString('px ', (string) $layout['gridTemplateColumns']);
                } else {
                    self::assertGreaterThanOrEqual($layout['slideWidth'] - 12, $layout['captionWidth']);
                    self::assertLessThanOrEqual($layout['slideWidth'], $layout['captionWidth']);
                    self::assertGreaterThan($layout['captionClientHeight'], $layout['captionScrollHeight']);
                }

                $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
            }

            $this->assertNoBrowserSevereErrors($client);
        } finally {
            $this->replaceFixtureVideoCaption($originalCaption);
        }
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

    private function currentSourceForSelector(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
        string $selector,
    ): string {
        $image = $webDriver->findElement(WebDriverBy::cssSelector($selector));
        $webDriver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$image]);

        /** @var string $currentSource */
        $currentSource = (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $image): string|false {
            $source = $webDriver->executeScript('return arguments[0].currentSrc || "";', [$image]);

            return is_string($source) && $source !== '' ? $source : false;
        });

        return $currentSource;
    }

    /**
     * @return array{
     *     src: string,
     *     srcset: string,
     *     sizes: string,
     *     loading: string,
     *     currentSrc: string,
     *     width: int,
     *     height: int
     * }
     */
    private function responsiveImageData(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
        string $selector,
    ): array {
        $image = $webDriver->findElement(WebDriverBy::cssSelector($selector));
        $webDriver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$image]);

        /** @var array{currentSrc: string, width: int, height: int} $rendered */
        $rendered = (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $image): array|false {
            $data = $webDriver->executeScript(
                'return {currentSrc: arguments[0].currentSrc || "", width: arguments[0].naturalWidth || 0, height: arguments[0].naturalHeight || 0};',
                [$image],
            );

            return is_array($data)
                && is_string($data['currentSrc'] ?? null)
                && $data['currentSrc'] !== ''
                && is_int($data['width'] ?? null)
                && $data['width'] > 0
                && is_int($data['height'] ?? null)
                && $data['height'] > 0
                ? $data
                : false;
        });

        return [
            'src' => (string) $image->getAttribute('src'),
            'srcset' => (string) $image->getAttribute('srcset'),
            'sizes' => (string) $image->getAttribute('sizes'),
            'loading' => (string) $image->getAttribute('loading'),
            'currentSrc' => $rendered['currentSrc'],
            'width' => $rendered['width'],
            'height' => $rendered['height'],
        ];
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

    private function replaceFixtureVideoCaption(?string $caption): ?string
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $video = $entityManager->getRepository(MediaAsset::class)->findOneBy([
                'externalUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ]);
            self::assertInstanceOf(MediaAsset::class, $video);
            $originalCaption = $video->getCaption();
            $video->setCaption($caption);
            $entityManager->flush();

            return $originalCaption;
        } finally {
            self::ensureKernelShutdown();
        }
    }

    private function ensureFixturePanoramaIsLinkedToCityVisit(): string
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $cityVisit = $entityManager->getRepository(CityVisitDraft::class)->findOneBy([
            'slug' => 'visiter-collioure-a-pied',
        ]);
        $panorama = $entityManager->getRepository(MediaAsset::class)->findOneBy([
            'mediaType' => MediaType::Image,
            'imageType' => ImageType::Degree360,
        ]);
        self::assertInstanceOf(CityVisitDraft::class, $cityVisit);
        self::assertInstanceOf(MediaAsset::class, $panorama);

        $existingLink = $entityManager->getRepository(CityVisitDraftMedia::class)->findOneBy([
            'cityVisitDraft' => $cityVisit,
            'mediaAsset' => $panorama,
        ]);
        if (!$existingLink instanceof CityVisitDraftMedia) {
            $entityManager->persist((new CityVisitDraftMedia())
                ->setCityVisitDraft($cityVisit)
                ->setMediaAsset($panorama)
                ->setRole(MediaRole::Gallery)
                ->setPosition(99));
            $entityManager->flush();
        }

        $slug = $cityVisit->getSlug();
        self::assertNotNull($slug);
        self::ensureKernelShutdown();

        return $slug;
    }

    private function resourceWasRequested(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
        string $fragment,
    ): bool {
        return (bool) $webDriver->executeScript(
            'return performance.getEntriesByType("resource").some((entry) => entry.name.includes(arguments[0]));',
            [$fragment],
        );
    }

    private function ensureFixtureStandardGalleryVariants(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $variantService = $container->get(MediaVariantService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(MediaVariantService::class, $variantService);

        $generated = 0;
        $standardMedia = $entityManager->getRepository(MediaAsset::class)->findBy([
            'mediaType' => MediaType::Image,
            'imageType' => ImageType::Standard,
        ]);
        foreach ($standardMedia as $media) {
            if (!$media instanceof MediaAsset) {
                continue;
            }

            if ($variantService->hasUsableVariants($media)) {
                ++$generated;

                continue;
            }

            $result = $variantService->generateForMedia($media, force: true);
            if ($result['status'] === 'generated') {
                ++$generated;
            }
        }

        self::assertGreaterThan(0, $generated);
        $entityManager->flush();
        self::ensureKernelShutdown();
    }

    /**
     * @return array{finished_at: ?\DateTimeImmutable, media_roles: array<int, MediaRole>}
     */
    private function prepareHomepageStandardFixturePhoto(): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $cityVisit = $entityManager->getRepository(CityVisitDraft::class)->findOneBy([
                'slug' => 'visiter-collioure-a-pied',
            ]);
            self::assertInstanceOf(CityVisitDraft::class, $cityVisit);

            $standardCover = null;
            $mediaRoles = [];
            foreach ($cityVisit->getMediaLinks() as $link) {
                $linkId = $link->getId();
                self::assertIsInt($linkId);
                $mediaRoles[$linkId] = $link->getRole();

                $media = $link->getMediaAsset();
                if (
                    $standardCover === null
                    && $media instanceof MediaAsset
                    && $media->getMediaType() === MediaType::Image
                    && $media->getImageType() === ImageType::Standard
                ) {
                    $standardCover = $link;
                }
            }

            self::assertNotNull($standardCover);
            foreach ($cityVisit->getMediaLinks() as $link) {
                $link->setRole($link === $standardCover ? MediaRole::Cover : MediaRole::Gallery);
            }

            $fixtureState = [
                'finished_at' => $cityVisit->getFinishedAt(),
                'media_roles' => $mediaRoles,
            ];
            $cityVisit->setFinishedAt(new \DateTimeImmutable('+1 day'));
            $entityManager->flush();

            return $fixtureState;
        } finally {
            self::ensureKernelShutdown();
        }
    }

    /** @param array{finished_at: ?\DateTimeImmutable, media_roles: array<int, MediaRole>} $fixtureState */
    private function restoreHomepageStandardFixturePhoto(array $fixtureState): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $cityVisit = $entityManager->getRepository(CityVisitDraft::class)->findOneBy([
                'slug' => 'visiter-collioure-a-pied',
            ]);
            self::assertInstanceOf(CityVisitDraft::class, $cityVisit);

            $cityVisit->setFinishedAt($fixtureState['finished_at']);
            foreach ($cityVisit->getMediaLinks() as $link) {
                $linkId = $link->getId();
                self::assertIsInt($linkId);
                self::assertArrayHasKey($linkId, $fixtureState['media_roles']);
                $link->setRole($fixtureState['media_roles'][$linkId]);
            }
            $entityManager->flush();
            $entityManager->clear();

            $restoredVisit = $entityManager->getRepository(CityVisitDraft::class)->findOneBy([
                'slug' => 'visiter-collioure-a-pied',
            ]);
            self::assertInstanceOf(CityVisitDraft::class, $restoredVisit);
            self::assertSame(
                $fixtureState['finished_at']?->format(DATE_ATOM),
                $restoredVisit->getFinishedAt()?->format(DATE_ATOM),
            );
            foreach ($restoredVisit->getMediaLinks() as $link) {
                $linkId = $link->getId();
                self::assertIsInt($linkId);
                self::assertSame($fixtureState['media_roles'][$linkId], $link->getRole());
            }
        } finally {
            self::ensureKernelShutdown();
        }
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

    private function disableBrowserCache(
        \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver,
    ): void {
        $devTools = new ChromeDevToolsDriver($webDriver);
        $devTools->execute('Network.enable');
        $devTools->execute('Network.setCacheDisabled', ['cacheDisabled' => true]);
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
