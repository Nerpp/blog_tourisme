<?php

namespace App\Tests\E2E;

use App\Entity\HikeDraft;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverWait;

final class StudioMobileNavigationPantherTest extends PantherTestCase
{
    public function testStudioMobileNavigationClosesAndKeepsSaveButtonBoundToMainForm(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $hikeId = $this->fixtureHikeId();

        $client = self::createBrowser();
        $webDriver = $client->getWebDriver();
        $webDriver->manage()->window()->setSize(new WebDriverDimension(780, 900));

        $this->loginAsFixtureAdmin($client);
        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $hikeId));
        $client->waitFor('[data-studio-quick-nav].is-collapsed');

        self::assertSelectorIsVisible('[data-studio-quick-nav-toggle]');
        self::assertSelectorExists('[data-studio-quick-nav-toggle][aria-expanded="false"]');
        self::assertFalse($webDriver->findElement(WebDriverBy::cssSelector('[data-studio-quick-nav-panel]'))->isDisplayed());

        /** @var array{formAttribute: string|null, formOwnerId: string|null, formExists: bool} $formBinding */
        $formBinding = $webDriver->executeScript(<<<'JS'
            const button = document.querySelector('.studio-quick-nav__submit');
            const formId = button?.getAttribute('form') || null;

            return {
                formAttribute: formId,
                formOwnerId: button?.form?.id || null,
                formExists: formId !== null && document.getElementById(formId) instanceof HTMLFormElement,
            };
        JS);

        self::assertSame('studio-hike-main-form', $formBinding['formAttribute']);
        self::assertSame($formBinding['formAttribute'], $formBinding['formOwnerId']);
        self::assertTrue($formBinding['formExists']);

        $toggle = $webDriver->findElement(WebDriverBy::cssSelector('[data-studio-quick-nav-toggle]'));
        $toggle->click();
        $client->waitFor('[data-studio-quick-nav-toggle][aria-expanded="true"]');

        self::assertSelectorNotExists('[data-studio-quick-nav].is-collapsed');
        self::assertTrue($webDriver->findElement(WebDriverBy::cssSelector('[data-studio-quick-nav-panel]'))->isDisplayed());

        $webDriver->findElement(WebDriverBy::tagName('body'))->sendKeys(WebDriverKeys::ESCAPE);
        $client->waitFor('[data-studio-quick-nav].is-collapsed');

        self::assertSelectorExists('[data-studio-quick-nav-toggle][aria-expanded="false"]');

        $toggle->click();
        $client->waitFor('[data-studio-quick-nav-toggle][aria-expanded="true"]');
        $webDriver->findElement(WebDriverBy::cssSelector('.studio-quick-nav__links a[href="#studio-location"]'))->click();

        (new WebDriverWait($webDriver, 8))->until(static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const nav = document.querySelector('[data-studio-quick-nav]');
            const toggle = document.querySelector('[data-studio-quick-nav-toggle]');

            return window.location.hash === '#studio-location'
                && nav?.classList.contains('is-collapsed')
                && toggle?.getAttribute('aria-expanded') === 'false';
        JS));

        self::assertSelectorExists('#studio-location');
        self::assertSelectorExists('[data-studio-quick-nav].is-collapsed');
        self::assertFalse($webDriver->findElement(WebDriverBy::cssSelector('[data-studio-quick-nav-panel]'))->isDisplayed());
    }

    private function fixtureHikeId(): int
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hike = $entityManager->getRepository(HikeDraft::class)->findOneBy(['slug' => 'randonnee-brouillon-admin']);
        self::assertInstanceOf(HikeDraft::class, $hike);
        $hikeId = $hike->getId();
        self::assertIsInt($hikeId);
        self::ensureKernelShutdown();

        return $hikeId;
    }

    private function loginAsFixtureAdmin(\Symfony\Component\Panther\Client $client): void
    {
        $client->request('GET', '/login');

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys('admin-test@example.test');
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys('PasswordAdmin2026!');
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
