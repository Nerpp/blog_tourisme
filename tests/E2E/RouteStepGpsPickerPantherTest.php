<?php

namespace App\Tests\E2E;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteStepGpsPickerPantherTest extends PantherTestCase
{
    /** @return iterable<string, array{string}> */
    public static function routeKindProvider(): iterable
    {
        yield 'randonnée' => ['hike'];
        yield 'visite de ville' => ['city_visit'];
    }

    #[DataProvider('routeKindProvider')]
    public function testExistingStepCoordinatesAreAdjustedWithoutLosingLocalState(string $routeKind): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createRouteContext($routeKind);
        $storedBefore = $this->storedRouteState($routeKind, $context['draftId']);
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);
        $webDriver->manage()->window()->setSize(new WebDriverDimension(1400, 1000));
        $this->blockOpenStreetMapRequests($webDriver);

        $client->request('GET', $context['editUrl'].'?e2e_frontend_assets=1');
        $this->waitForRouteGpsUi($client, $webDriver);
        $this->assertPageHasBuiltAssets(
            $client,
            'assets/js/route-step-ordering.js',
            'assets/js/location-geopoint-picker.js',
        );

        $unsavedTitle = 'Titre local non enregistre '.$routeKind;
        $unsavedNote = 'Note locale non enregistree '.$routeKind;
        $initialDom = $this->prepareUnsavedStepState(
            $webDriver,
            $context['targetPointId'],
            $unsavedTitle,
            $unsavedNote,
        );
        self::assertSame($context['pointIds'], $initialDom['order']);
        self::assertSame(['Étape 1', 'Étape 2', 'Étape 3'], $initialDom['numbers']);
        self::assertSame(['1', '2', '3'], $initialDom['positions']);
        self::assertSame(3, $initialDom['adjustButtonCount']);
        self::assertSame('Ajuster la position', $initialDom['buttonText']);
        self::assertSame('Ajuster la position GPS de l’étape 2', $initialDom['buttonLabel']);
        self::assertSame('false', $initialDom['buttonExpanded']);
        self::assertSame($routeKind, $initialDom['buttonRouteKind']);
        self::assertSame((string) $context['draftId'], $initialDom['buttonDraftId']);
        self::assertSame((string) $context['targetPointId'], $initialDom['buttonStepId']);
        self::assertNotSame('', $initialDom['buttonToken']);
        self::assertStringContainsString('/coordinates', $initialDom['buttonUrl']);

        $openedState = $this->openPositionEditor($webDriver, $context['targetPointId']);
        self::assertFalse($openedState['editorHidden']);
        self::assertSame((string) $context['targetPointId'], $openedState['activeStepId']);
        self::assertSame('true', $openedState['buttonExpanded']);
        self::assertTrue($openedState['headingFocused']);
        self::assertGreaterThan(0, $openedState['mapWidth']);
        self::assertGreaterThan(0, $openedState['mapHeight']);
        self::assertSame(1, $openedState['markerCount']);
        self::assertIsArray($openedState['coordinates']);
        self::assertEqualsWithDelta(43.1001000, $openedState['coordinates']['latitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta(3.1001000, $openedState['coordinates']['longitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta(5.0, $openedState['coordinates']['accuracy'] ?? null, 0.000000001);
        self::assertSame('route-step-current', $openedState['coordinates']['source'] ?? null);
        self::assertSame('43.1001000', $openedState['pendingLatitude']);
        self::assertSame('3.1001000', $openedState['pendingLongitude']);

        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            window.__routeStepGpsExchange = null;
            window.__routeStepCoordinatesUpdated = null;
            document.addEventListener('route-step:coordinates-updated', (event) => {
                window.__routeStepCoordinatesUpdated = event.detail;
            }, { once: true });
            window.fetch = async (input, options = {}) => {
                const response = await nativeFetch(input, options);
                if (String(input).includes('/coordinates')) {
                    window.__routeStepGpsExchange = {
                        url: String(input),
                        request: JSON.parse(options.body),
                        response: await response.clone().json()
                    };
                }

                return response;
            };
        JS);

        $invalidManualState = $webDriver->executeScript(<<<'JS'
            const picker = document.querySelector(
                '[data-route-step-position-editor] [data-location-picker]'
            );
            const latitude = picker.querySelector('[data-latitude-input]');
            const longitude = picker.querySelector('[data-longitude-input]');
            latitude.value = '91';
            latitude.dispatchEvent(new Event('input', { bubbles: true }));
            longitude.value = '';
            longitude.dispatchEvent(new Event('input', { bubbles: true }));

            return {
                latitude: latitude.value,
                longitude: longitude.value,
                coordinates: picker.locationGeopointPicker.getCoordinates(),
                markerCount: picker.querySelectorAll('.leaflet-marker-icon').length,
                validateDisabled: picker.querySelector('[data-validate-point]').disabled,
                status: picker.querySelector('[data-map-status]').textContent.trim()
            };
        JS);
        self::assertIsArray($invalidManualState);
        self::assertSame('91', $invalidManualState['latitude']);
        self::assertSame('', $invalidManualState['longitude']);
        self::assertNull($invalidManualState['coordinates']);
        self::assertSame(0, $invalidManualState['markerCount']);
        self::assertTrue($invalidManualState['validateDisabled']);
        self::assertStringContainsString(
            'latitude et une longitude valides',
            $invalidManualState['status'],
        );
        self::assertNull($webDriver->executeScript('return window.__routeStepGpsExchange;'));

        $expectedLatitude = '44.4444567';
        $expectedLongitude = '4.4445678';
        $expectedAccuracy = '13.5';
        $setterState = $this->setPickerCoordinates(
            $webDriver,
            $expectedLatitude,
            $expectedLongitude,
            $expectedAccuracy,
        );
        self::assertTrue($setterState['result']);
        self::assertSame(1, $setterState['markerCount']);
        self::assertFalse($setterState['validateDisabled']);
        self::assertSame($expectedLatitude, $setterState['latitudeInput']);
        self::assertSame($expectedLongitude, $setterState['longitudeInput']);
        self::assertSame($expectedAccuracy, $setterState['accuracyInput']);
        self::assertEqualsWithDelta((float) $expectedLatitude, $setterState['coordinates']['latitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta((float) $expectedLongitude, $setterState['coordinates']['longitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta((float) $expectedAccuracy, $setterState['coordinates']['accuracy'] ?? null, 0.000000001);

        $currentUrl = $webDriver->getCurrentURL();
        $this->clickPickerValidation($webDriver);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const editor = root?.querySelector('[data-route-step-position-editor]');
            const button = root?.querySelector('[data-route-step][data-step-id="{$context['targetPointId']}"] [data-route-step-adjust-position]');

            return editor?.hidden === true
                && button?.getAttribute('aria-expanded') === 'false'
                && document.activeElement === button
                && root?.querySelector('[data-route-step-order-status]')?.textContent.trim()
                    === 'La position GPS de l’étape a été enregistrée.';
        JS));

        $finalDom = $this->routeDomState($webDriver, $context['targetPointId']);
        self::assertSame($currentUrl, $webDriver->getCurrentURL());
        self::assertSame($initialDom['order'], $finalDom['order']);
        self::assertSame($initialDom['numbers'], $finalDom['numbers']);
        self::assertSame($initialDom['positions'], $finalDom['positions']);
        self::assertSame($unsavedTitle, $finalDom['title']);
        self::assertSame($unsavedNote, $finalDom['note']);
        self::assertTrue($finalDom['sameStepNode']);
        self::assertTrue($finalDom['sameFormNode']);
        self::assertSame($expectedLatitude, $finalDom['latitude']);
        self::assertSame($expectedLongitude, $finalDom['longitude']);
        self::assertSame($expectedAccuracy, $finalDom['accuracy']);
        self::assertFalse($finalDom['gpsInherited']);
        self::assertFalse($finalDom['gpsDirty']);
        self::assertSame('0', $finalDom['inheritedInput']);
        self::assertSame('GPS personnalisé', $finalDom['gpsBadge']);
        self::assertFalse($finalDom['mapLinkHidden']);
        self::assertSame($expectedLatitude, $finalDom['buttonLatitude']);
        self::assertSame($expectedLongitude, $finalDom['buttonLongitude']);
        self::assertSame('false', $finalDom['buttonCoordinatesInherited']);
        self::assertTrue($finalDom['buttonFocused']);

        $exchange = $webDriver->executeScript('return window.__routeStepGpsExchange;');
        self::assertIsArray($exchange);
        self::assertStringContainsString('/coordinates', $exchange['url'] ?? '');
        $this->assertPayloadKeys($exchange['request'] ?? null, [
            'latitude',
            'longitude',
            'expectedPosition',
            'expectedCoordinates',
            'accuracy',
        ]);
        self::assertSame($expectedLatitude, $exchange['request']['latitude'] ?? null);
        self::assertSame($expectedLongitude, $exchange['request']['longitude'] ?? null);
        self::assertSame($expectedAccuracy, $exchange['request']['accuracy'] ?? null);
        self::assertSame(2, $exchange['request']['expectedPosition'] ?? null);
        $this->assertExpectedCoordinatesPayload($exchange['request']['expectedCoordinates'] ?? null, [
            'latitude' => '43.1001',
            'longitude' => '3.1001',
            'accuracy' => '5',
            'coordinatesInherited' => true,
        ]);
        self::assertTrue($exchange['response']['success'] ?? false);
        self::assertSame($context['pointIds'][0], $exchange['response']['primaryLocationStepId'] ?? null);
        self::assertSame($context['targetPointId'], $exchange['response']['step']['id'] ?? null);
        self::assertSame(2, $exchange['response']['step']['position'] ?? null);
        self::assertFalse($exchange['response']['step']['coordinatesInherited'] ?? true);
        $updatedEvent = $webDriver->executeScript('return window.__routeStepCoordinatesUpdated;');
        self::assertIsArray($updatedEvent);
        self::assertSame($routeKind, $updatedEvent['routeKind'] ?? null);
        self::assertSame($context['draftId'], $updatedEvent['draftId'] ?? null);
        self::assertSame($context['targetPointId'], $updatedEvent['stepId'] ?? null);
        self::assertSame($context['pointIds'][0], $updatedEvent['primaryLocationStepId'] ?? null);
        self::assertSame(2, $updatedEvent['position'] ?? null);
        self::assertFalse($updatedEvent['coordinatesInherited'] ?? true);

        $storedAfter = $this->storedRouteState($routeKind, $context['draftId']);
        self::assertSame($context['pointIds'], array_column($storedAfter, 'id'));
        self::assertSame([1, 2, 3], array_column($storedAfter, 'position'));
        foreach ($storedAfter as $index => $storedPoint) {
            if ($storedPoint['id'] !== $context['targetPointId']) {
                self::assertSame($storedBefore[$index], $storedPoint);
                continue;
            }

            self::assertEqualsWithDelta((float) $expectedLatitude, $storedPoint['latitude'], 0.000000001);
            self::assertEqualsWithDelta((float) $expectedLongitude, $storedPoint['longitude'], 0.000000001);
            self::assertEqualsWithDelta((float) $expectedAccuracy, $storedPoint['accuracy'], 0.000000001);
            self::assertFalse($storedPoint['inherited']);
            self::assertSame(2, $storedPoint['position']);
            self::assertSame($storedBefore[$index]['title'], $storedPoint['title']);
            self::assertSame($storedBefore[$index]['note'], $storedPoint['note']);
        }
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testCoordinateFreeStepStartsWithoutSelectionAndCanBeSavedOnMobile(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createRouteContext('hike', targetWithoutCoordinates: true);
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);
        $this->blockOpenStreetMapRequests($webDriver);
        $webDriver->manage()->window()->setSize(new WebDriverDimension(390, 844));

        $client->request('GET', $context['editUrl'].'?e2e_frontend_assets=1');
        $this->waitForRouteGpsUi($client, $webDriver);
        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            window.__routeStepEmptyGpsFetchCount = 0;
            window.__routeStepEmptyGpsRequest = null;
            window.fetch = async (input, options = {}) => {
                if (String(input).includes('/coordinates')) {
                    window.__routeStepEmptyGpsFetchCount += 1;
                    window.__routeStepEmptyGpsRequest = JSON.parse(options.body);
                }

                return nativeFetch(input, options);
            };
        JS);

        $openedState = $this->openPositionEditor($webDriver, $context['targetPointId']);
        self::assertLessThanOrEqual(500, $openedState['viewportWidth']);
        self::assertFalse($openedState['editorHidden']);
        self::assertNull($openedState['coordinates']);
        self::assertSame(0, $openedState['markerCount']);
        self::assertTrue($openedState['validateDisabled']);
        self::assertSame('', $openedState['pendingLatitude']);
        self::assertSame('', $openedState['pendingLongitude']);
        self::assertGreaterThan(0, $openedState['mapWidth']);
        self::assertLessThanOrEqual($openedState['editorWidth'] + 1, $openedState['mapWidth']);
        self::assertSame(0, $webDriver->executeScript('return window.__routeStepEmptyGpsFetchCount;'));

        $storedWithoutSelection = $this->storedRouteState('hike', $context['draftId']);
        $emptyPoint = $this->pointState($storedWithoutSelection, $context['targetPointId']);
        self::assertNull($emptyPoint['latitude']);
        self::assertNull($emptyPoint['longitude']);
        self::assertTrue($emptyPoint['inherited']);

        $invalidState = $this->setPickerCoordinates($webDriver, '91', '181', '5');
        self::assertFalse($invalidState['result']);
        self::assertNull($invalidState['coordinates']);
        self::assertSame(0, $invalidState['markerCount']);
        self::assertTrue($invalidState['validateDisabled']);
        self::assertSame(0, $webDriver->executeScript('return window.__routeStepEmptyGpsFetchCount;'));

        $expectedLatitude = '45.0000001';
        $expectedLongitude = '5.0000002';
        $validState = $this->setPickerCoordinates($webDriver, $expectedLatitude, $expectedLongitude, '');
        self::assertTrue($validState['result']);
        self::assertSame(1, $validState['markerCount']);
        self::assertFalse($validState['validateDisabled']);
        $this->clickPickerValidation($webDriver);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const root = document.querySelector('[data-route-step-ordering]');

            return root?.querySelector('[data-route-step-position-editor]')?.hidden === true
                && root.querySelector('[data-route-step-order-status]')?.textContent.trim()
                    === 'La position GPS de l’étape a été enregistrée.';
        JS));

        self::assertSame(1, $webDriver->executeScript('return window.__routeStepEmptyGpsFetchCount;'));
        $request = $webDriver->executeScript('return window.__routeStepEmptyGpsRequest;');
        $this->assertPayloadKeys($request, [
            'latitude',
            'longitude',
            'expectedPosition',
            'expectedCoordinates',
        ]);
        self::assertArrayNotHasKey('accuracy', $request);
        $storedAfter = $this->storedRouteState('hike', $context['draftId']);
        $savedPoint = $this->pointState($storedAfter, $context['targetPointId']);
        self::assertEqualsWithDelta((float) $expectedLatitude, $savedPoint['latitude'], 0.000000001);
        self::assertEqualsWithDelta((float) $expectedLongitude, $savedPoint['longitude'], 0.000000001);
        self::assertNull($savedPoint['accuracy']);
        self::assertFalse($savedPoint['inherited']);
        self::assertSame(2, $savedPoint['position']);
        self::assertSame($context['pointIds'], array_column($storedAfter, 'id'));
        self::assertSame([1, 2, 3], array_column($storedAfter, 'position'));
        $finalDom = $this->routeDomState($webDriver, $context['targetPointId']);
        self::assertSame('Étape 2', $finalDom['numbers'][1] ?? null);
        self::assertSame('GPS personnalisé', $finalDom['gpsBadge']);
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testServerFailureKeepsEditorMarkerAndUnsavedStepState(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createRouteContext('hike');
        $storedBefore = $this->storedRouteState('hike', $context['draftId']);
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);
        $webDriver->manage()->window()->setSize(new WebDriverDimension(1400, 1000));
        $this->blockOpenStreetMapRequests($webDriver);

        $client->request('GET', $context['editUrl'].'?e2e_frontend_assets=1');
        $this->waitForRouteGpsUi($client, $webDriver);
        $unsavedTitle = 'Titre conserve apres erreur GPS';
        $unsavedNote = 'Note conservee apres erreur GPS';
        $initialDom = $this->prepareUnsavedStepState(
            $webDriver,
            $context['targetPointId'],
            $unsavedTitle,
            $unsavedNote,
        );
        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            window.__routeStepFailedGpsExchange = null;
            window.fetch = async (input, options = {}) => {
                if (!String(input).includes('/coordinates')) {
                    return nativeFetch(input, options);
                }

                window.__routeStepFailedGpsExchange = {
                    url: String(input),
                    request: JSON.parse(options.body)
                };

                return new Response(JSON.stringify({
                    success: false,
                    error: 'Echec serveur GPS E2E controle',
                    code: 'write_conflict'
                }), {
                    status: 409,
                    headers: { 'Content-Type': 'application/json' }
                });
            };
        JS);

        $this->openPositionEditor($webDriver, $context['targetPointId']);
        $expectedLatitude = '46.1234567';
        $expectedLongitude = '6.7654321';
        $expectedAccuracy = '21';
        $this->setPickerCoordinates($webDriver, $expectedLatitude, $expectedLongitude, $expectedAccuracy);
        $this->clickPickerValidation($webDriver);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const root = document.querySelector('[data-route-step-ordering]');
            const editor = root?.querySelector('[data-route-step-position-editor]');
            const status = editor?.querySelector('[data-route-step-position-status]');
            const validate = editor?.querySelector('[data-validate-point]');

            return editor?.hidden === false
                && status?.classList.contains('is-error')
                && status.textContent.includes('Echec serveur GPS E2E controle')
                && validate?.disabled === false;
        JS));

        $failedState = $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const step = root.querySelector('[data-route-step][data-step-id="{$context['targetPointId']}"]');
            const editor = root.querySelector('[data-route-step-position-editor]');
            const picker = editor.querySelector('[data-location-picker]');
            const button = step.querySelector('[data-route-step-adjust-position]');

            return {
                editorHidden: editor.hidden,
                activeStepId: editor.dataset.activeStepId || '',
                buttonExpanded: button.getAttribute('aria-expanded'),
                markerCount: picker.querySelectorAll('.leaflet-marker-icon').length,
                coordinates: picker.locationGeopointPicker.getCoordinates(),
                status: editor.querySelector('[data-route-step-position-status]').textContent.trim(),
                latitude: step.querySelector('[data-gps-latitude]').value,
                longitude: step.querySelector('[data-gps-longitude]').value,
                accuracy: step.querySelector('[data-gps-accuracy]').value,
                gpsInherited: step.dataset.gpsInherited === 'true',
                gpsDirty: step.dataset.gpsDirty === 'true',
                gpsBadge: step.querySelector('[data-route-step-gps-badge]').textContent.trim(),
                title: step.querySelector('[data-route-step-title-input]').value,
                note: step.querySelector('textarea[name="note"]').value,
                sameStepNode: step === window.__routeStepGpsOriginalStep,
                sameFormNode: step.querySelector('[data-route-step-form]') === window.__routeStepGpsOriginalForm,
                order: [...root.querySelectorAll('[data-route-step]')].map((item) => Number(item.dataset.stepId)),
                numbers: [...root.querySelectorAll('[data-route-step-number]')].map((item) => item.textContent.trim()),
                positions: [...root.querySelectorAll('[data-route-step]')].map((item) => item.dataset.stepPosition)
            };
        JS);
        self::assertIsArray($failedState);
        self::assertFalse($failedState['editorHidden']);
        self::assertSame((string) $context['targetPointId'], $failedState['activeStepId']);
        self::assertSame('true', $failedState['buttonExpanded']);
        self::assertSame(1, $failedState['markerCount']);
        self::assertEqualsWithDelta((float) $expectedLatitude, $failedState['coordinates']['latitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta((float) $expectedLongitude, $failedState['coordinates']['longitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta((float) $expectedAccuracy, $failedState['coordinates']['accuracy'] ?? null, 0.000000001);
        self::assertSame('Echec serveur GPS E2E controle', $failedState['status']);
        self::assertSame('43.1001', $failedState['latitude']);
        self::assertSame('3.1001', $failedState['longitude']);
        self::assertSame('5', $failedState['accuracy']);
        self::assertTrue($failedState['gpsInherited']);
        self::assertFalse($failedState['gpsDirty']);
        self::assertSame('GPS repris depuis l’étape précédente', $failedState['gpsBadge']);
        self::assertSame($unsavedTitle, $failedState['title']);
        self::assertSame($unsavedNote, $failedState['note']);
        self::assertTrue($failedState['sameStepNode']);
        self::assertTrue($failedState['sameFormNode']);
        self::assertSame($initialDom['order'], $failedState['order']);
        self::assertSame($initialDom['numbers'], $failedState['numbers']);
        self::assertSame($initialDom['positions'], $failedState['positions']);

        $exchange = $webDriver->executeScript('return window.__routeStepFailedGpsExchange;');
        self::assertIsArray($exchange);
        self::assertSame($expectedLatitude, $exchange['request']['latitude'] ?? null);
        self::assertSame($expectedLongitude, $exchange['request']['longitude'] ?? null);
        self::assertSame($expectedAccuracy, $exchange['request']['accuracy'] ?? null);
        self::assertSame(2, $exchange['request']['expectedPosition'] ?? null);
        $this->assertExpectedCoordinatesPayload($exchange['request']['expectedCoordinates'] ?? null, [
            'latitude' => '43.1001',
            'longitude' => '3.1001',
            'accuracy' => '5',
            'coordinatesInherited' => true,
        ]);
        self::assertSame($storedBefore, $this->storedRouteState('hike', $context['draftId']));
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testInvalidSuccessfulResponseKeepsUncertainEditorLockedAndRetryable(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createRouteContext('hike');
        $storedBefore = $this->storedRouteState('hike', $context['draftId']);
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);
        $webDriver->manage()->window()->setSize(new WebDriverDimension(1400, 1000));
        $this->blockOpenStreetMapRequests($webDriver);

        $client->request('GET', $context['editUrl'].'?e2e_frontend_assets=1');
        $this->waitForRouteGpsUi($client, $webDriver);
        $unsavedTitle = 'Titre conserve en etat GPS incertain';
        $unsavedNote = 'Note conservee en etat GPS incertain';
        $initialDom = $this->prepareUnsavedStepState(
            $webDriver,
            $context['targetPointId'],
            $unsavedTitle,
            $unsavedNote,
        );
        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            window.__routeStepUncertainGpsExchange = null;
            window.fetch = async (input, options = {}) => {
                if (!String(input).includes('/coordinates')) {
                    return nativeFetch(input, options);
                }

                window.__routeStepUncertainGpsExchange = {
                    url: String(input),
                    request: JSON.parse(options.body)
                };

                return new Response('{"success":true', {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });
            };
        JS);

        $this->openPositionEditor($webDriver, $context['targetPointId']);
        $expectedLatitude = '47.1234567';
        $expectedLongitude = '7.7654321';
        $expectedAccuracy = '34';
        $this->setPickerCoordinates($webDriver, $expectedLatitude, $expectedLongitude, $expectedAccuracy);
        $this->clickPickerValidation($webDriver);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const root = document.querySelector('[data-route-step-ordering]');
            const editor = root?.querySelector('[data-route-step-position-editor]');
            const picker = editor?.querySelector('[data-location-picker]');
            const status = editor?.querySelector('[data-route-step-position-status]');
            const validate = editor?.querySelector('[data-validate-point]');
            const close = editor?.querySelector('[data-route-step-position-editor-close]');

            return editor?.hidden === false
                && editor.hasAttribute('data-server-state-uncertain')
                && editor.classList.contains('is-coordinate-uncertain')
                && !editor.hasAttribute('aria-busy')
                && status?.classList.contains('is-error')
                && status.textContent.includes('Impossible de confirmer l’état de la position côté serveur')
                && validate?.disabled === false
                && close?.disabled === true
                && picker?.classList.contains('is-coordinate-locked');
        JS));

        /** @var array<string, mixed> $uncertainState */
        $uncertainState = $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const step = root.querySelector('[data-route-step][data-step-id="{$context['targetPointId']}"]');
            const editor = root.querySelector('[data-route-step-position-editor]');
            const picker = editor.querySelector('[data-location-picker]');
            const validate = picker.querySelector('[data-validate-point]');
            const close = editor.querySelector('[data-route-step-position-editor-close]');
            const button = step.querySelector('[data-route-step-adjust-position]');
            const routeControls = [...root.querySelectorAll([
                '[data-route-step-up]',
                '[data-route-step-down]',
                '[data-route-step-drag-handle]',
                '[data-route-step-copy-previous]',
                '[data-route-step-adjust-position]',
                '[data-route-step-position-editor-close]'
            ].join(','))];
            const dragHandles = [...root.querySelectorAll('[data-route-step-drag-handle]')];
            const submitButtons = [...root.querySelectorAll('form button[type="submit"]')];
            const pickerControls = [...picker.querySelectorAll('button, input, select, textarea')]
                .filter((control) => !control.matches('[data-validate-point]'));
            const positionStatusBeforeSubmit = editor
                .querySelector('[data-route-step-position-status]').textContent.trim();
            const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
            const submitAllowed = step.querySelector('[data-route-step-form]').dispatchEvent(submitEvent);
            const positionStatusAfterSubmit = editor
                .querySelector('[data-route-step-position-status]').textContent.trim();
            const beforeUnloadEvent = new Event('beforeunload', { cancelable: true });
            const beforeUnloadAllowed = window.dispatchEvent(beforeUnloadEvent);

            return {
                editorHidden: editor.hidden,
                editorUncertain: editor.hasAttribute('data-server-state-uncertain'),
                editorBusy: editor.hasAttribute('aria-busy'),
                activeStepId: editor.dataset.activeStepId || '',
                buttonExpanded: button.getAttribute('aria-expanded'),
                markerCount: picker.querySelectorAll('.leaflet-marker-icon').length,
                coordinates: picker.locationGeopointPicker.getCoordinates(),
                pickerLocked: picker.classList.contains('is-coordinate-locked'),
                validateDisabled: validate.disabled,
                validateAriaDisabled: validate.getAttribute('aria-disabled'),
                closeDisabled: close.disabled,
                closeAriaDisabled: close.getAttribute('aria-disabled'),
                routeControlCount: routeControls.length,
                allRouteControlsDisabled: routeControls.every((control) => control.disabled),
                dragHandleCount: dragHandles.length,
                allDragHandlesDisabled: dragHandles.every((control) => control.disabled),
                submitButtonCount: submitButtons.length,
                allSubmitButtonsDisabled: submitButtons.every((control) => control.disabled),
                pickerControlCount: pickerControls.length,
                allPickerControlsDisabled: pickerControls.every((control) => control.disabled),
                submitPrevented: !submitAllowed || submitEvent.defaultPrevented,
                beforeUnloadPrevented: !beforeUnloadAllowed || beforeUnloadEvent.defaultPrevented,
                positionStatusBeforeSubmit,
                positionStatusAfterSubmit,
                title: step.querySelector('[data-route-step-title-input]').value,
                note: step.querySelector('textarea[name="note"]').value,
                latitude: step.querySelector('[data-gps-latitude]').value,
                longitude: step.querySelector('[data-gps-longitude]').value,
                accuracy: step.querySelector('[data-gps-accuracy]').value,
                gpsInherited: step.dataset.gpsInherited === 'true',
                gpsDirty: step.dataset.gpsDirty === 'true',
                order: [...root.querySelectorAll('[data-route-step]')].map((item) => Number(item.dataset.stepId)),
                numbers: [...root.querySelectorAll('[data-route-step-number]')].map((item) => item.textContent.trim()),
                positions: [...root.querySelectorAll('[data-route-step]')].map((item) => item.dataset.stepPosition)
            };
        JS);
        self::assertFalse($uncertainState['editorHidden']);
        self::assertTrue($uncertainState['editorUncertain']);
        self::assertFalse($uncertainState['editorBusy']);
        self::assertSame((string) $context['targetPointId'], $uncertainState['activeStepId']);
        self::assertSame('true', $uncertainState['buttonExpanded']);
        self::assertSame(1, $uncertainState['markerCount']);
        self::assertEqualsWithDelta((float) $expectedLatitude, $uncertainState['coordinates']['latitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta((float) $expectedLongitude, $uncertainState['coordinates']['longitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta((float) $expectedAccuracy, $uncertainState['coordinates']['accuracy'] ?? null, 0.000000001);
        self::assertTrue($uncertainState['pickerLocked']);
        self::assertFalse($uncertainState['validateDisabled']);
        self::assertNull($uncertainState['validateAriaDisabled']);
        self::assertTrue($uncertainState['closeDisabled']);
        self::assertSame('true', $uncertainState['closeAriaDisabled']);
        self::assertGreaterThan(0, $uncertainState['routeControlCount']);
        self::assertTrue($uncertainState['allRouteControlsDisabled']);
        self::assertGreaterThan(0, $uncertainState['dragHandleCount']);
        self::assertTrue($uncertainState['allDragHandlesDisabled']);
        self::assertGreaterThan(0, $uncertainState['submitButtonCount']);
        self::assertTrue($uncertainState['allSubmitButtonsDisabled']);
        self::assertGreaterThan(0, $uncertainState['pickerControlCount']);
        self::assertTrue($uncertainState['allPickerControlsDisabled']);
        self::assertTrue($uncertainState['submitPrevented']);
        self::assertTrue($uncertainState['beforeUnloadPrevented']);
        self::assertSame(
            'Impossible de confirmer l’état de la position côté serveur. Le point choisi est conservé : réessayez exactement cette position ou rechargez la page.',
            $uncertainState['positionStatusBeforeSubmit'],
        );
        self::assertSame(
            'La position GPS doit être confirmée ou la page rechargée avant de valider un formulaire.',
            $uncertainState['positionStatusAfterSubmit'],
        );
        self::assertSame($unsavedTitle, $uncertainState['title']);
        self::assertSame($unsavedNote, $uncertainState['note']);
        self::assertSame('43.1001', $uncertainState['latitude']);
        self::assertSame('3.1001', $uncertainState['longitude']);
        self::assertSame('5', $uncertainState['accuracy']);
        self::assertTrue($uncertainState['gpsInherited']);
        self::assertFalse($uncertainState['gpsDirty']);
        self::assertSame($initialDom['order'], $uncertainState['order']);
        self::assertSame($initialDom['numbers'], $uncertainState['numbers']);
        self::assertSame($initialDom['positions'], $uncertainState['positions']);

        $exchange = $webDriver->executeScript('return window.__routeStepUncertainGpsExchange;');
        self::assertIsArray($exchange);
        self::assertSame($expectedLatitude, $exchange['request']['latitude'] ?? null);
        self::assertSame($expectedLongitude, $exchange['request']['longitude'] ?? null);
        self::assertSame($expectedAccuracy, $exchange['request']['accuracy'] ?? null);
        self::assertSame(2, $exchange['request']['expectedPosition'] ?? null);
        $this->assertExpectedCoordinatesPayload($exchange['request']['expectedCoordinates'] ?? null, [
            'latitude' => '43.1001',
            'longitude' => '3.1001',
            'accuracy' => '5',
            'coordinatesInherited' => true,
        ]);
        self::assertSame($storedBefore, $this->storedRouteState('hike', $context['draftId']));
        $this->assertNoBrowserSevereErrors($client);
    }

    /**
     * @return array{email: string, password: string, routeKind: string, draftId: int, editUrl: string, pointIds: list<int>, targetPointId: int}
     */
    private function createRouteContext(string $routeKind, bool $targetWithoutCoordinates = false): array
    {
        self::assertContains($routeKind, ['hike', 'city_visit']);
        self::bootKernel();
        $container = static::getContainer();
        $rateLimiterCache = $container->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();

        $email = $this->uniqueEmail('route-step-gps-'.$routeKind);
        $password = 'E2E Route Step GPS 2026 9!';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('E2E route step GPS '.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setIsVerified(true);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        if ($routeKind === 'hike') {
            $draft = (new HikeDraft())
                ->setTitle('Randonnée ajustement GPS E2E '.bin2hex(random_bytes(4)))
                ->setSlug('randonnee-ajustement-gps-e2e-'.bin2hex(random_bytes(5)))
                ->setStatus(HikeDraftStatus::Draft)
                ->setCreatedBy($user);
        } else {
            $draft = (new CityVisitDraft())
                ->setTitle('Visite ajustement GPS E2E '.bin2hex(random_bytes(4)))
                ->setSlug('visite-ajustement-gps-e2e-'.bin2hex(random_bytes(5)))
                ->setStatus(CityVisitDraftStatus::Draft)
                ->setCreatedBy($user);
        }

        $first = $this->createPoint($draft, 1, 43.1001, 3.1001, 5.0, false);
        $target = $this->createPoint(
            $draft,
            2,
            $targetWithoutCoordinates ? null : 43.1001,
            $targetWithoutCoordinates ? null : 3.1001,
            $targetWithoutCoordinates ? null : 5.0,
            true,
        );
        $third = $this->createPoint($draft, 3, 43.3003, 3.3003, 9.0, false);

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        foreach ([$user, $draft, $first, $target, $third] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $draftId = $draft->getId();
        $firstId = $first->getId();
        $targetId = $target->getId();
        $thirdId = $third->getId();
        self::assertIsInt($draftId);
        self::assertIsInt($firstId);
        self::assertIsInt($targetId);
        self::assertIsInt($thirdId);
        self::ensureKernelShutdown();

        return [
            'email' => $email,
            'password' => $password,
            'routeKind' => $routeKind,
            'draftId' => $draftId,
            'editUrl' => $routeKind === 'hike'
                ? sprintf('/admin/studio/hikes/%d/edit', $draftId)
                : sprintf('/admin/studio/city-visits/%d/edit', $draftId),
            'pointIds' => [$firstId, $targetId, $thirdId],
            'targetPointId' => $targetId,
        ];
    }

    private function createPoint(
        HikeDraft|CityVisitDraft $draft,
        int $position,
        ?float $latitude,
        ?float $longitude,
        ?float $accuracy,
        bool $inherited,
    ): HikePoint|CityVisitPoint {
        if ($draft instanceof HikeDraft) {
            $point = (new HikePoint())
                ->setHikeDraft($draft)
                ->setType(match ($position) {
                    1 => HikePointType::Start,
                    2 => HikePointType::Interest,
                    default => HikePointType::Viewpoint,
                });
            $draft->addPoint($point);
        } else {
            $point = (new CityVisitPoint())
                ->setCityVisitDraft($draft)
                ->setType(match ($position) {
                    1 => CityVisitPointType::Start,
                    2 => CityVisitPointType::Monument,
                    default => CityVisitPointType::Viewpoint,
                });
            $draft->addPoint($point);
        }

        return $point
            ->setTitle(match ($position) {
                1 => 'Départ GPS E2E',
                2 => 'Étape cible GPS E2E',
                default => 'Belvédère GPS E2E',
            })
            ->setNote(match ($position) {
                1 => 'Note départ enregistrée',
                2 => 'Note cible enregistrée',
                default => 'Note belvédère enregistrée',
            })
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setAccuracy($accuracy)
            ->setPosition($position)
            ->setCoordinatesInherited($inherited);
    }

    private function loginAsAdmin(string $email, string $password): Client
    {
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

    private function waitForRouteGpsUi(Client $client, RemoteWebDriver $webDriver): void
    {
        $client->waitFor('[data-route-step-ordering][data-route-step-ordering-ready="true"]');
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const root = document.querySelector('[data-route-step-ordering]');
            const picker = root?.querySelector('[data-route-step-position-editor] [data-location-picker]');

            return picker?.dataset.locationPickerReady === 'true'
                && typeof picker.locationGeopointPicker?.setCoordinates === 'function'
                && typeof picker.locationGeopointPicker?.getCoordinates === 'function';
        JS));
    }

    /** @return array<string, mixed> */
    private function prepareUnsavedStepState(
        RemoteWebDriver $webDriver,
        int $pointId,
        string $title,
        string $note,
    ): array {
        /** @var array<string, mixed> $state */
        $state = $webDriver->executeScript(<<<'JS'
            const root = document.querySelector('[data-route-step-ordering]');
            const step = root.querySelector(`[data-route-step][data-step-id="${arguments[0]}"]`);
            const form = step.querySelector('[data-route-step-form]');
            const title = step.querySelector('[data-route-step-title-input]');
            const note = step.querySelector('textarea[name="note"]');
            title.value = arguments[1];
            title.dispatchEvent(new Event('input', { bubbles: true }));
            note.value = arguments[2];
            window.__routeStepGpsOriginalStep = step;
            window.__routeStepGpsOriginalForm = form;
            const button = step.querySelector('[data-route-step-adjust-position]');

            return {
                order: [...root.querySelectorAll('[data-route-step]')].map((item) => Number(item.dataset.stepId)),
                numbers: [...root.querySelectorAll('[data-route-step-number]')].map((item) => item.textContent.trim()),
                positions: [...root.querySelectorAll('[data-route-step]')].map((item) => item.dataset.stepPosition),
                adjustButtonCount: root.querySelectorAll('[data-route-step-adjust-position]').length,
                buttonText: button.textContent.trim(),
                buttonLabel: button.getAttribute('aria-label'),
                buttonExpanded: button.getAttribute('aria-expanded'),
                buttonRouteKind: button.dataset.routeKind,
                buttonDraftId: button.dataset.draftId,
                buttonStepId: button.dataset.stepId,
                buttonToken: button.dataset.coordinatesToken,
                buttonUrl: button.dataset.coordinatesUrl
            };
        JS, [$pointId, $title, $note]);

        return $state;
    }

    /** @return array<string, mixed> */
    private function openPositionEditor(RemoteWebDriver $webDriver, int $pointId): array
    {
        $selector = sprintf(
            '[data-route-step][data-step-id="%d"] [data-route-step-adjust-position]',
            $pointId,
        );
        $webDriver->executeScript(
            'document.querySelector(arguments[0])?.scrollIntoView({ behavior: "instant", block: "center" });',
            [$selector],
        );
        $webDriver->findElement(WebDriverBy::cssSelector($selector))->click();

        /** @var array<string, mixed> $state */
        $state = (new WebDriverWait($webDriver, 8))->until(static function () use ($webDriver, $pointId): array|false {
            /** @var array<string, mixed> $candidate */
            $candidate = $webDriver->executeScript(<<<'JS'
                const root = document.querySelector('[data-route-step-ordering]');
                const editor = root?.querySelector('[data-route-step-position-editor]');
                const picker = editor?.querySelector('[data-location-picker]');
                const map = picker?.querySelector('[data-map-container]');
                const button = root?.querySelector(`[data-route-step][data-step-id="${arguments[0]}"] [data-route-step-adjust-position]`);
                const pendingText = picker?.querySelector('[data-map-pending-coordinates]')?.textContent || '';
                const pendingLines = pendingText.split('\n').map((line) => line.split(':').slice(1).join(':').trim());

                return {
                    editorHidden: editor?.hidden !== false,
                    activeStepId: editor?.dataset.activeStepId || '',
                    buttonExpanded: button?.getAttribute('aria-expanded') || '',
                    headingFocused: document.activeElement === editor?.querySelector('h3'),
                    markerCount: picker?.querySelectorAll('.leaflet-marker-icon').length || 0,
                    coordinates: picker?.locationGeopointPicker?.getCoordinates?.() ?? null,
                    validateDisabled: picker?.querySelector('[data-validate-point]')?.disabled === true,
                    pendingLatitude: pendingLines[0] || '',
                    pendingLongitude: pendingLines[1] || '',
                    mapWidth: map?.getBoundingClientRect().width || 0,
                    mapHeight: map?.getBoundingClientRect().height || 0,
                    editorWidth: editor?.getBoundingClientRect().width || 0,
                    viewportWidth: window.innerWidth
                };
            JS, [$pointId]);

            return $candidate['editorHidden'] === false
                && $candidate['activeStepId'] === (string) $pointId
                && $candidate['buttonExpanded'] === 'true'
                && $candidate['headingFocused'] === true
                && $candidate['mapWidth'] > 0
                && $candidate['mapHeight'] > 0
                    ? $candidate
                    : false;
        });

        return $state;
    }

    /** @return array<string, mixed> */
    private function setPickerCoordinates(
        RemoteWebDriver $webDriver,
        string $latitude,
        string $longitude,
        string $accuracy,
    ): array {
        /** @var array<string, mixed> $state */
        $state = $webDriver->executeScript(<<<'JS'
            const picker = document.querySelector(
                '[data-route-step-position-editor] [data-location-picker]'
            );
            const result = picker.locationGeopointPicker.setCoordinates(arguments[0], arguments[1], {
                accuracy: arguments[2],
                source: 'panther-api',
                zoom: 17,
                statusMessage: 'Point injecté par l’API publique pour validation E2E.'
            });

            return {
                result,
                coordinates: picker.locationGeopointPicker.getCoordinates(),
                markerCount: picker.querySelectorAll('.leaflet-marker-icon').length,
                validateDisabled: picker.querySelector('[data-validate-point]').disabled,
                latitudeInput: picker.querySelector('[data-latitude-input]').value,
                longitudeInput: picker.querySelector('[data-longitude-input]').value,
                accuracyInput: picker.querySelector('[data-gps-accuracy-input]').value
            };
        JS, [$latitude, $longitude, $accuracy]);

        return $state;
    }

    private function clickPickerValidation(RemoteWebDriver $webDriver): void
    {
        $selector = '[data-route-step-position-editor] [data-validate-point]';
        $webDriver->executeScript(
            'document.querySelector(arguments[0])?.scrollIntoView({ behavior: "instant", block: "center" });',
            [$selector],
        );
        $webDriver->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    /**
     * @param mixed $actual
     * @param array{latitude: string|null, longitude: string|null, accuracy: string|null, coordinatesInherited: bool} $expected
     */
    private function assertExpectedCoordinatesPayload(mixed $actual, array $expected): void
    {
        self::assertIsArray($actual);

        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys);
        sort($expectedKeys);
        self::assertSame($expectedKeys, $actualKeys);

        foreach ($expected as $key => $value) {
            self::assertSame($value, $actual[$key]);
        }
    }

    /** @param list<string> $expectedKeys */
    private function assertPayloadKeys(mixed $payload, array $expectedKeys): void
    {
        self::assertIsArray($payload);

        $actualKeys = array_keys($payload);
        sort($actualKeys);
        sort($expectedKeys);
        self::assertSame($expectedKeys, $actualKeys);
    }

    /** @return array<string, mixed> */
    private function routeDomState(RemoteWebDriver $webDriver, int $pointId): array
    {
        /** @var array<string, mixed> $state */
        $state = $webDriver->executeScript(<<<'JS'
            const root = document.querySelector('[data-route-step-ordering]');
            const step = root.querySelector(`[data-route-step][data-step-id="${arguments[0]}"]`);
            const button = step.querySelector('[data-route-step-adjust-position]');

            return {
                order: [...root.querySelectorAll('[data-route-step]')].map((item) => Number(item.dataset.stepId)),
                numbers: [...root.querySelectorAll('[data-route-step-number]')].map((item) => item.textContent.trim()),
                positions: [...root.querySelectorAll('[data-route-step]')].map((item) => item.dataset.stepPosition),
                title: step.querySelector('[data-route-step-title-input]').value,
                note: step.querySelector('textarea[name="note"]').value,
                latitude: step.querySelector('[data-gps-latitude]').value,
                longitude: step.querySelector('[data-gps-longitude]').value,
                accuracy: step.querySelector('[data-gps-accuracy]').value,
                gpsInherited: step.dataset.gpsInherited === 'true',
                gpsDirty: step.dataset.gpsDirty === 'true',
                inheritedInput: step.querySelector('[data-route-step-inherited-input]').value,
                gpsBadge: step.querySelector('[data-route-step-gps-badge]').textContent.trim(),
                mapLinkHidden: step.querySelector('[data-route-step-map-link]').hidden,
                buttonLatitude: button.dataset.latitude,
                buttonLongitude: button.dataset.longitude,
                buttonCoordinatesInherited: button.dataset.coordinatesInherited,
                buttonFocused: document.activeElement === button,
                sameStepNode: step === window.__routeStepGpsOriginalStep,
                sameFormNode: step.querySelector('[data-route-step-form]') === window.__routeStepGpsOriginalForm
            };
        JS, [$pointId]);

        return $state;
    }

    /**
     * @return list<array{id: int, position: int, latitude: float|null, longitude: float|null, accuracy: float|null, inherited: bool, title: string|null, note: string|null}>
     */
    private function storedRouteState(string $routeKind, int $draftId): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $pointClass = $routeKind === 'hike' ? HikePoint::class : CityVisitPoint::class;
        $association = $routeKind === 'hike' ? 'hikeDraft' : 'cityVisitDraft';
        $points = $entityManager->getRepository($pointClass)->findBy(
            [$association => $draftId],
            ['position' => 'ASC'],
        );

        $state = [];
        foreach ($points as $point) {
            self::assertInstanceOf($pointClass, $point);
            $id = $point->getId();
            self::assertIsInt($id);
            $state[] = [
                'id' => $id,
                'position' => $point->getPosition(),
                'latitude' => $point->getLatitude(),
                'longitude' => $point->getLongitude(),
                'accuracy' => $point->getAccuracy(),
                'inherited' => $point->hasInheritedCoordinates(),
                'title' => $point->getTitle(),
                'note' => $point->getNote(),
            ];
        }
        self::ensureKernelShutdown();

        return $state;
    }

    /**
     * @param list<array{id: int, position: int, latitude: float|null, longitude: float|null, accuracy: float|null, inherited: bool, title: string|null, note: string|null}> $states
     *
     * @return array{id: int, position: int, latitude: float|null, longitude: float|null, accuracy: float|null, inherited: bool, title: string|null, note: string|null}
     */
    private function pointState(array $states, int $pointId): array
    {
        foreach ($states as $state) {
            if ($state['id'] === $pointId) {
                return $state;
            }
        }

        self::fail(sprintf('Point %d absent de l’état stocké.', $pointId));
    }

    private function blockOpenStreetMapRequests(RemoteWebDriver $webDriver): void
    {
        $devTools = new ChromeDevToolsDriver($webDriver);
        $devTools->execute('Network.enable');
        $devTools->execute('Network.setBlockedURLs', [
            'urls' => [
                '*://openstreetmap.org/*',
                '*://*.openstreetmap.org/*',
            ],
        ]);
    }

    private function waitUntil(RemoteWebDriver $webDriver, callable $condition): void
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
