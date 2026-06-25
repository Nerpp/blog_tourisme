<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverWait;

final class PublicListingsPantherTest extends PantherTestCase
{
    public function testDestinationCountersNavigateToPublicLists(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();

        foreach ([
            'articles' => ['/articles', 'articles'],
            'hikes' => ['/randonnees', 'hikes'],
            'city-visits' => ['/visites', 'city-visits'],
        ] as $counter => [$path, $listing]) {
            $client->request('GET', '/destinations');
            $client->waitFor(sprintf('[data-destination-summary-link="%s"]', $counter));

            $webDriver = $client->getWebDriver();
            $link = $webDriver->findElement(WebDriverBy::cssSelector(sprintf('[data-destination-summary-link="%s"]', $counter)));
            self::assertSame($path, parse_url((string) $link->getAttribute('href'), PHP_URL_PATH));

            $link->click();
            $client->waitFor(sprintf('[data-public-listing="%s"]', $listing));

            self::assertStringContainsString($path, $client->getCurrentURL());
            self::assertSelectorExists(sprintf('[data-public-listing="%s"] [data-public-content-search]', $listing));
        }
    }

    public function testArticleAutocompleteSupportsEscapeAndKeyboardNavigation(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $client->request('GET', '/articles');
        $client->waitFor('[data-public-listing="articles"] [data-public-search-input]');
        $this->assertPageHasBuiltAssets($client, 'assets/app.js', 'assets/entries/public-listing.js');
        $this->assertPageDoesNotHaveBuiltAssets(
            $client,
            'assets/entries/public-detail.js',
            'assets/entries/article-show.js',
            'assets/entries/comments.js',
        );

        $webDriver = $client->getWebDriver();
        $input = $webDriver->findElement(WebDriverBy::cssSelector('[data-public-search-input]'));
        $input->sendKeys('Fo');
        $this->waitForSuggestionText($webDriver, 'Visiter le Fort Saint-Elme');

        $input->sendKeys(WebDriverKeys::ESCAPE);
        $this->waitForSuggestionsClosed($webDriver);

        $input->clear();
        $input->sendKeys('Fort');
        $this->waitForSuggestionText($webDriver, 'Visiter le Fort Saint-Elme');
        $input->click();
        $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ARROW_DOWN);
        $activeSuggestionHref = $this->waitForActiveSuggestionHref($webDriver);
        $webDriver->getKeyboard()->sendKeys(WebDriverKeys::ENTER);

        $expectedPath = (string) parse_url($activeSuggestionHref, PHP_URL_PATH);
        (new WebDriverWait($webDriver, 8))->until(static fn () => parse_url($webDriver->getCurrentURL(), PHP_URL_PATH) === $expectedPath);
        self::assertSame($expectedPath, parse_url($webDriver->getCurrentURL(), PHP_URL_PATH));
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testSharedAutocompleteAppearsOnHikesAndVisitsWithoutDraftSuggestions(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();

        $client->request('GET', '/randonnees');
        $client->waitFor('[data-public-listing="hikes"] [data-public-search-input]');
        $this->assertPageHasBuiltAssets($client, 'assets/app.js', 'assets/entries/public-listing.js');
        $input = $webDriver->findElement(WebDriverBy::cssSelector('[data-public-search-input]'));
        $input->sendKeys('Canigou');
        $this->waitForSuggestionText($webDriver, 'Boucle du Canigou découverte');
        self::assertStringNotContainsString('Randonnée brouillon admin', $this->visibleSuggestionsText($webDriver));

        $input->clear();
        $input->sendKeys('brouillon');
        $this->waitForSuggestionsClosed($webDriver);

        $client->request('GET', '/visites');
        $client->waitFor('[data-public-listing="city-visits"] [data-public-search-input]');
        $this->assertPageHasBuiltAssets($client, 'assets/app.js', 'assets/entries/public-listing.js');
        $input = $webDriver->findElement(WebDriverBy::cssSelector('[data-public-search-input]'));
        $input->sendKeys('Collioure');
        $this->waitForSuggestionText($webDriver, 'Visiter Collioure à pied');
        self::assertStringNotContainsString('Visite brouillon non publique', $this->visibleSuggestionsText($webDriver));
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testSearchFormSubmitsGetWhenNoSuggestionIsSelected(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $client->request('GET', '/randonnees');
        $client->waitFor('[data-public-listing="hikes"] [data-public-search-input]');

        $webDriver = $client->getWebDriver();
        $input = $webDriver->findElement(WebDriverBy::cssSelector('[data-public-search-input]'));
        $input->sendKeys('Canigou');
        $this->waitForSuggestionText($webDriver, 'Boucle du Canigou découverte');
        $input->sendKeys(WebDriverKeys::ENTER);

        (new WebDriverWait($webDriver, 8))->until(static fn () => str_contains($webDriver->getCurrentURL(), '/randonnees?q=Canigou'));
        self::assertSelectorTextContains('[data-public-listing="hikes"]', 'Boucle du Canigou découverte');
    }

    private function waitForSuggestionText(RemoteWebDriver $webDriver, string $text): WebDriverElement
    {
        return (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $text): WebDriverElement|false {
            foreach ($webDriver->findElements(WebDriverBy::cssSelector('[data-public-search-option]')) as $suggestion) {
                if ($suggestion->isDisplayed() && str_contains($suggestion->getText(), $text)) {
                    return $suggestion;
                }
            }

            return false;
        });
    }

    private function waitForSuggestionsClosed(RemoteWebDriver $webDriver): void
    {
        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'const list = document.querySelector("[data-public-search-suggestions]"); return !list || list.hidden === true || list.children.length === 0;'
        ));
    }

    private function waitForActiveSuggestionHref(RemoteWebDriver $webDriver): string
    {
        /** @var string $href */
        $href = (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver): string|false {
            $href = $webDriver->executeScript(
                'return document.querySelector("[data-public-search-option].is-active")?.getAttribute("href") || "";'
            );

            return is_string($href) && $href !== '' ? $href : false;
        });

        return $href;
    }

    private function visibleSuggestionsText(RemoteWebDriver $webDriver): string
    {
        /** @var string $text */
        $text = $webDriver->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('[data-public-search-option]'))
                .filter((item) => item.offsetParent !== null)
                .map((item) => item.textContent || '')
                .join('\n');
        JS);

        return $text;
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
