<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;

final class QuickDistanceLocationPickerPantherTest extends PantherTestCase
{
    public function testCityVisitDistanceCreationSubmitsSelectedCommuneAndValidatedPointThroughBrowser(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = $this->loginAsAdmin();
        $webDriver = $client->getWebDriver();
        $commune = [
            'nom' => 'Ceret E2E',
            'code' => '66049',
            'codesPostaux' => ['66400'],
            'centre' => ['coordinates' => [2.7489000, 42.4851000]],
            'departement' => ['code' => '66', 'nom' => 'Pyrenees-Orientales'],
            'region' => ['nom' => 'Occitanie'],
        ];

        $client->request('GET', '/admin/quick?type=city_visit&mode=distance&e2e_frontend_assets=1');
        self::assertSelectorTextContains('body', 'Créer une visite de ville à distance');
        $this->mockCommuneSearch($webDriver, $commune);

        $payload = $this->selectCommuneValidatePointAndReadPayload($client, 'Ceret');

        self::assertSame('quick_city_visit', $payload['contextType']);
        self::assertSame('city', $payload['type']);
        self::assertSame('Ceret E2E', $payload['cityName']);
        self::assertSame('66049', $payload['code']);
        self::assertSame('66400', $payload['postalCode']);
        self::assertSame('Pyrenees-Orientales', $payload['departmentName']);
        self::assertSame('66', $payload['departmentCode']);
        self::assertSame('Occitanie', $payload['regionName']);
        self::assertNotSame('', $payload['latitude']);
        self::assertNotSame('', $payload['longitude']);

        $webDriver->findElement(WebDriverBy::cssSelector('[data-quick-destination-submit]'))->click();
        $this->waitForCurrentUrlContains($webDriver, '/admin/studio/city-visits/');

        self::assertSelectorTextContains('h1', 'Visite de ville à Ceret E2E');
        self::assertSelectorTextContains('body', 'Ceret E2E');
        $this->assertStudioLocationFieldsMatchPayload($webDriver, $payload);
    }

    public function testHikeDistanceCreationSubmitsSelectedCommuneAndValidatedPointThroughBrowser(): void
    {
        $this->skipIfFrontendBuildIsMissing();

        $client = $this->loginAsAdmin();
        $webDriver = $client->getWebDriver();
        $commune = [
            'nom' => 'Bors E2E',
            'code' => '16050',
            'codesPostaux' => ['16190'],
            'centre' => ['coordinates' => [0.0607000, 45.3631000]],
            'departement' => ['code' => '16', 'nom' => 'Charente'],
            'region' => ['nom' => 'Nouvelle-Aquitaine'],
        ];

        $client->request('GET', '/admin/quick?type=hike&mode=distance&e2e_frontend_assets=1');
        self::assertSelectorTextContains('body', 'Créer une randonnée à distance');
        $this->mockCommuneSearch($webDriver, $commune);

        $payload = $this->selectCommuneValidatePointAndReadPayload($client, 'Bors');

        self::assertSame('quick_hike', $payload['contextType']);
        self::assertSame('city', $payload['type']);
        self::assertSame('Bors E2E', $payload['cityName']);
        self::assertSame('16050', $payload['code']);
        self::assertSame('16190', $payload['postalCode']);
        self::assertSame('Charente', $payload['departmentName']);
        self::assertSame('16', $payload['departmentCode']);
        self::assertSame('Nouvelle-Aquitaine', $payload['regionName']);
        self::assertNotSame('', $payload['latitude']);
        self::assertNotSame('', $payload['longitude']);

        $webDriver->findElement(WebDriverBy::cssSelector('[data-quick-destination-submit]'))->click();
        $this->waitForCurrentUrlContains($webDriver, '/admin/studio/hikes/');

        self::assertSelectorTextContains('h1', 'Randonnée à Bors E2E');
        self::assertSelectorTextContains('body', 'Bors E2E');
        $this->assertStudioLocationFieldsMatchPayload($webDriver, $payload);
    }

    private function loginAsAdmin(): \Symfony\Component\Panther\Client
    {
        $email = $this->uniqueEmail('quick-distance');
        $password = 'E2E Quick Distance 2026 9!';
        $this->createVerifiedUser($email, $password, ['ROLE_ADMIN', 'ROLE_USER']);

        $client = self::createBrowser();
        $client->request('GET', '/login');

        if ($client->getCrawler()->filter('.logout-form')->count() > 0) {
            return $client;
        }

        self::assertSelectorIsVisible('form.login-form');

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys($email);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys($password);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.logout-form');

        return $client;
    }

    /** @param array<string, mixed> $commune */
    private function mockCommuneSearch(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, array $commune): void
    {
        $communesJson = json_encode([$commune], JSON_THROW_ON_ERROR);
        $webDriver->executeScript(<<<JS
            window.fetch = async () => new Response('$communesJson', {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            });
        JS);
    }

    /** @return array<string, string> */
    private function selectCommuneValidatePointAndReadPayload(\Symfony\Component\Panther\Client $client, string $query): array
    {
        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::cssSelector('[data-commune-search-input]'))->sendKeys($query);
        $client->waitFor('[data-commune-search-results] button');
        $webDriver->findElement(WebDriverBy::cssSelector('[data-commune-search-results] button'))->click();

        $this->waitUntil($webDriver, static fn () => $webDriver->executeScript(<<<'JS'
            const form = document.querySelector('[data-quick-destination-form]');
            return form?.querySelector('[name="cityName"]')?.value !== ''
                && form?.querySelector('[name="code"]')?.value !== ''
                && document.querySelector('[data-quick-destination-submit]')?.disabled === false;
        JS));

        $this->assertLocationFieldsAreInsideSubmittedForm($webDriver);
        $this->clickMapAndValidatePoint($webDriver);

        $this->waitUntil($webDriver, static fn () => $webDriver->executeScript(<<<'JS'
            const form = document.querySelector('[data-quick-destination-form]');
            return form?.querySelector('[name="latitude"]')?.value !== ''
                && form?.querySelector('[name="longitude"]')?.value !== ''
                && document.querySelector('[data-map-status]')?.textContent.includes('Point validé sur la carte.');
        JS));

        /** @var array<string, string> $payload */
        $payload = $webDriver->executeScript(<<<'JS'
            const form = document.querySelector('[data-quick-destination-form]');
            return Object.fromEntries(new FormData(form).entries());
        JS);

        return $payload;
    }

    private function assertLocationFieldsAreInsideSubmittedForm(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver): void
    {
        $fields = $webDriver->executeScript(<<<'JS'
            const form = document.querySelector('[data-quick-destination-form]');
            const requiredNames = ['cityName', 'code', 'postalCode', 'departmentName', 'departmentCode', 'regionName', 'latitude', 'longitude'];

            return requiredNames.map((name) => {
                const input = form?.querySelector(`[name="${name}"]`) || null;
                const duplicateIds = input?.id ? document.querySelectorAll(`[id="${input.id}"]`).length : 0;

                return {
                    name,
                    inputName: input?.name || null,
                    value: input?.value || '',
                    insideForm: input instanceof HTMLInputElement && form.contains(input),
                    duplicateIds,
                };
            });
        JS);

        self::assertIsArray($fields);
        foreach ($fields as $field) {
            self::assertIsArray($field);
            self::assertSame($field['name'], $field['inputName']);
            self::assertTrue($field['insideForm'], sprintf('Input "%s" must be inside the submitted form.', $field['name']));
            self::assertLessThanOrEqual(1, $field['duplicateIds'], sprintf('Input "%s" must not have a duplicated id.', $field['name']));
        }
    }

    private function clickMapAndValidatePoint(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver): void
    {
        $webDriver->executeScript(<<<'JS'
            const map = document.querySelector('[data-map-container]');
            const rect = map.getBoundingClientRect();
            map.dispatchEvent(new MouseEvent('click', {
                clientX: rect.left + rect.width * 0.62,
                clientY: rect.top + rect.height * 0.38,
                bubbles: true,
                cancelable: true,
                view: window
            }));
        JS);
        $webDriver->findElement(WebDriverBy::cssSelector('[data-validate-point]'))->click();
    }

    private function waitForCurrentUrlContains(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, string $expectedPath): void
    {
        $this->waitUntil($webDriver, static fn () => str_contains($webDriver->getCurrentURL(), $expectedPath));
    }

    /** @param array<string, string> $payload */
    private function assertStudioLocationFieldsMatchPayload(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, array $payload): void
    {
        /** @var array{commune: string|null, code: string|null, latitude: string|null, longitude: string|null} $studioValues */
        $studioValues = $webDriver->executeScript(<<<'JS'
            return {
                commune: document.querySelector('[name="detectedCommuneName"]')?.value || null,
                code: document.querySelector('[name="detectedCommuneCode"]')?.value || null,
                latitude: document.querySelector('[name="locationLatitude"]')?.value || null,
                longitude: document.querySelector('[name="locationLongitude"]')?.value || null,
            };
        JS);

        self::assertSame($payload['cityName'], $studioValues['commune']);
        self::assertSame($payload['code'], $studioValues['code']);
        self::assertEqualsWithDelta((float) $payload['latitude'], (float) $studioValues['latitude'], 0.0000001);
        self::assertEqualsWithDelta((float) $payload['longitude'], (float) $studioValues['longitude'], 0.0000001);
    }

    private function waitUntil(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, callable $condition): void
    {
        (new WebDriverWait($webDriver, 8))->until($condition);
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
