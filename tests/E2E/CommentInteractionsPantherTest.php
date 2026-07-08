<?php

namespace App\Tests\E2E;

use App\DataFixtures\UserFixtures;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;

final class CommentInteractionsPantherTest extends PantherTestCase
{
    public function testReplyFormAndRepliesStayAttachedToTheirComment(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $this->loginAsFixtureUser($client);
        $client->request('GET', '/articles/que-faire-a-collioure-en-une-journee');
        $client->waitFor('[data-comment-replies-toggle]');
        $this->assertPageHasBuiltStyles($client, 'assets/app.js', 'assets/entries/comments.js', 'assets/entries/article-show.js');
        $this->assertPageHasBuiltScripts($client, 'assets/app.js', 'assets/entries/comments.js', 'assets/entries/article-show.js');

        $webDriver = $client->getWebDriver();

        /** @var array{rootId: string, repliesId: string, otherRootId: string, otherText: string} $context */
        $context = $webDriver->executeScript(<<<'JS'
            const repliesToggle = document.querySelector('[data-comment-replies-toggle]');
            const root = repliesToggle?.closest('.comment-thread > .comment-item');
            const otherRoot = Array.from(document.querySelectorAll('.comment-thread > .comment-item'))
                .find((comment) => comment !== root);

            return {
                rootId: root?.id || '',
                repliesId: repliesToggle?.getAttribute('aria-controls') || '',
                otherRootId: otherRoot?.id || '',
                otherText: otherRoot?.textContent?.trim() || '',
            };
        JS);

        self::assertNotSame('', $context['rootId']);
        self::assertNotSame('', $context['repliesId']);
        self::assertNotSame('', $context['otherRootId']);

        $rootSelector = '#'.$context['rootId'];
        $panelSelector = $rootSelector.' [data-comment-reply-panel]';
        $textareaSelector = $panelSelector.' textarea';

        $webDriver->findElement(WebDriverBy::cssSelector($panelSelector.' [data-comment-reply-toggle]'))->click();

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'const panel = document.querySelector(arguments[0]); return panel?.open === true && document.activeElement === panel.querySelector("textarea");',
            [$panelSelector]
        ));

        self::assertSelectorExists($panelSelector.' [data-comment-reply-toggle][aria-expanded="true"]');
        $textarea = $webDriver->findElement(WebDriverBy::cssSelector($textareaSelector));
        $textarea->sendKeys('Réponse temporaire Panther');

        $webDriver->findElement(WebDriverBy::cssSelector($panelSelector.' [data-comment-reply-cancel]'))->click();

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'const panel = document.querySelector(arguments[0]); return panel?.open === false && panel.querySelector("textarea")?.value === "";',
            [$panelSelector]
        ));

        self::assertSelectorExists($panelSelector.' [data-comment-reply-toggle][aria-expanded="false"]');
        self::assertSame(0, count($webDriver->findElements(WebDriverBy::cssSelector('[data-comment-reply-panel][open]'))));

        $webDriver->findElement(WebDriverBy::cssSelector($rootSelector.' [data-comment-replies-toggle]'))->click();

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(
            'const replies = document.getElementById(arguments[0]); return replies?.hidden === false && replies?.classList.contains("comment-replies--expanded");',
            [$context['repliesId']]
        ));

        self::assertSelectorExists($rootSelector.' [data-comment-replies-toggle][aria-expanded="true"]');
        self::assertSame(1, count($webDriver->findElements(WebDriverBy::cssSelector('[data-comment-replies]:not([hidden])'))));

        /** @var array{sameParent: bool, outsideOpenedReplies: bool, text: string} $otherState */
        $otherState = $webDriver->executeScript(<<<'JS'
            const otherRoot = document.getElementById(arguments[0]);
            const openedReplies = document.getElementById(arguments[1]);

            return {
                sameParent: otherRoot?.parentElement?.classList.contains('comment-thread') === true,
                outsideOpenedReplies: openedReplies?.contains(otherRoot) === false,
                text: otherRoot?.textContent?.trim() || '',
            };
        JS, [$context['otherRootId'], $context['repliesId']]);

        self::assertTrue($otherState['sameParent']);
        self::assertTrue($otherState['outsideOpenedReplies']);
        self::assertSame($context['otherText'], $otherState['text']);
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testNotificationsPageKeepsCommentStylesWithoutCommentScript(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = self::createBrowser();
        $this->loginAsFixtureUser($client);
        $client->request('GET', '/notifications/commentaires');
        $client->waitFor('.comment-notifications-page');

        self::assertSelectorNotExists('[data-comment-replies]');
        self::assertSelectorNotExists('[data-comment-replies-toggle]');
        self::assertSelectorNotExists('[data-comment-reply-panel]');
        self::assertSelectorNotExists('[data-comment-reply-form]');
        $this->assertPageHasBuiltStyles($client, 'assets/app.js', 'assets/entries/comments.js');
        $this->assertPageHasBuiltScripts($client, 'assets/app.js');
        $this->assertPageDoesNotHaveBuiltScripts($client, 'assets/entries/comments.js');
        $this->assertNoBrowserSevereErrors($client);
    }

    private function loginAsFixtureUser(\Symfony\Component\Panther\Client $client): void
    {
        $client->request('GET', '/login');
        $client->waitFor('body');

        $webDriver = $client->getWebDriver();
        if (count($webDriver->findElements(WebDriverBy::cssSelector('.logout-form'))) > 0) {
            return;
        }

        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys(UserFixtures::USER_EMAIL);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys(UserFixtures::USER_PASSWORD);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.logout-form');
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
