<?php

namespace App\Tests\E2E;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteStepOrderingPantherTest extends PantherTestCase
{
    public function testAccessibleControlsReorderExistingDomAndKeepUnsavedStepState(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHikeWithOrderedSteps();
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $context['hikeId']));
        $client->waitFor('[data-route-step-ordering][data-route-step-ordering-ready="true"]');
        $this->assertPageHasBuiltAssets($client, 'assets/js/route-step-ordering.js');

        $initialState = $this->prepareUnsavedStepState($webDriver, $context['pointIds'][1], $context['pointMediaId']);

        self::assertSame($context['pointIds'], $initialState['order']);
        self::assertSame(['Étape 1', 'Étape 2', 'Étape 3'], $initialState['numbers']);
        self::assertSame(3, $initialState['gpsComponentCount']);
        self::assertSame([1, 1, 1], $initialState['gpsComponentsPerStep']);
        self::assertSame(3, $initialState['readyGpsComponentCount']);
        self::assertSame(1, $initialState['routeMapCount']);
        self::assertTrue($initialState['duplicateWarningVisible']);
        self::assertTrue($initialState['firstUpDisabled']);
        self::assertTrue($initialState['lastDownDisabled']);

        $this->clickStepControl($webDriver, $context['pointIds'][2], 'up');
        $this->waitForSavedOrder($webDriver, [
            $context['pointIds'][0],
            $context['pointIds'][2],
            $context['pointIds'][1],
        ]);

        $inheritedAfterMove = $this->routeState($webDriver, $context['pointIds'][1], $context['pointMediaId']);
        self::assertSame('43.3003', $inheritedAfterMove['latitude']);
        self::assertSame('3.3003', $inheritedAfterMove['longitude']);
        self::assertSame('GPS repris depuis l’étape précédente', $inheritedAfterMove['gpsBadge']);
        self::assertTrue($inheritedAfterMove['duplicateWarningVisible']);
        self::assertTrue($inheritedAfterMove['sameStepNode']);
        self::assertTrue($inheritedAfterMove['sameFormNode']);
        self::assertTrue($inheritedAfterMove['sameMediaNode']);
        self::assertTrue($inheritedAfterMove['mediaStillNested']);
        self::assertSame('Titre non enregistre E2E', $inheritedAfterMove['title']);
        self::assertSame('Note non enregistree E2E', $inheritedAfterMove['note']);
        self::assertSame('Legende media non enregistree E2E', $inheritedAfterMove['mediaCaption']);
        $storedGpsStates = $this->storedGpsStates([$context['pointIds'][1], $context['pointIds'][2]]);
        self::assertEqualsWithDelta(43.3003, $storedGpsStates[$context['pointIds'][1]]['latitude'], 0.0000001);
        self::assertEqualsWithDelta(3.3003, $storedGpsStates[$context['pointIds'][1]]['longitude'], 0.0000001);
        self::assertEqualsWithDelta(9.0, $storedGpsStates[$context['pointIds'][1]]['accuracy'], 0.0000001);
        self::assertTrue($storedGpsStates[$context['pointIds'][1]]['inherited']);
        self::assertEqualsWithDelta(43.3003, $storedGpsStates[$context['pointIds'][2]]['latitude'], 0.0000001);
        self::assertEqualsWithDelta(3.3003, $storedGpsStates[$context['pointIds'][2]]['longitude'], 0.0000001);
        self::assertEqualsWithDelta(9.0, $storedGpsStates[$context['pointIds'][2]]['accuracy'], 0.0000001);
        self::assertFalse($storedGpsStates[$context['pointIds'][2]]['inherited']);

        $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="{$context['pointIds'][1]}"]');
            const latitude = step.querySelector('[data-gps-latitude]');
            const longitude = step.querySelector('[data-gps-longitude]');
            const accuracy = step.querySelector('[data-gps-accuracy]');
            latitude.value = '44.4444567';
            latitude.dispatchEvent(new Event('input', { bubbles: true }));
            longitude.value = '4.4444567';
            longitude.dispatchEvent(new Event('input', { bubbles: true }));
            accuracy.value = '17';
            accuracy.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="{$context['pointIds'][1]}"]');

            return step?.dataset.gpsInherited === 'false'
                && step.querySelector('[data-route-step-gps-badge]')?.textContent.includes('GPS personnalisé')
                && step.querySelector('[data-route-step-duplicate-warning]')?.hidden === true;
        JS));
        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            window.__routeStepExchange = null;
            window.fetch = async (input, options = {}) => {
                const response = await nativeFetch(input, options);
                if (String(input).includes('/points/reorder')) {
                    window.__routeStepExchange = {
                        request: JSON.parse(options.body),
                        response: await response.clone().json()
                    };
                }

                return response;
            };
        JS);

        $this->clickStepControl($webDriver, $context['pointIds'][0], 'down');
        $finalOrder = [
            $context['pointIds'][2],
            $context['pointIds'][0],
            $context['pointIds'][1],
        ];
        $this->waitForSavedOrder($webDriver, $finalOrder);

        $finalState = $this->routeState($webDriver, $context['pointIds'][1], $context['pointMediaId']);
        self::assertSame($finalOrder, $finalState['order']);
        self::assertSame(['Étape 1', 'Étape 2', 'Étape 3'], $finalState['numbers']);
        self::assertSame(['1', '2', '3', '4'], $finalState['insertionPositions']);
        self::assertSame('44.4444567', $finalState['latitude']);
        self::assertSame('4.4444567', $finalState['longitude']);
        self::assertSame('17', $finalState['accuracy']);
        self::assertFalse($finalState['gpsInherited']);
        self::assertFalse($finalState['gpsDirty']);
        self::assertSame('0', $finalState['inheritedInput']);
        self::assertSame('GPS personnalisé', $finalState['gpsBadge']);
        self::assertFalse($finalState['duplicateWarningVisible']);
        self::assertTrue($finalState['sameStepNode']);
        self::assertTrue($finalState['sameFormNode']);
        self::assertTrue($finalState['sameMediaNode']);
        self::assertTrue($finalState['mediaStillNested']);
        self::assertSame('Titre non enregistre E2E', $finalState['title']);
        self::assertSame('Note non enregistree E2E', $finalState['note']);
        self::assertSame('Legende media non enregistree E2E', $finalState['mediaCaption']);
        $exchange = $webDriver->executeScript('return window.__routeStepExchange;');
        self::assertIsArray($exchange);
        self::assertIsArray($exchange['request'] ?? null);
        self::assertSame($finalOrder, $exchange['request']['orderedIds'] ?? null);
        self::assertIsArray($exchange['request']['customizedGps'] ?? null);
        self::assertCount(1, $exchange['request']['customizedGps']);
        $customizedRequestState = $exchange['request']['customizedGps'][0] ?? null;
        self::assertIsArray($customizedRequestState);
        self::assertSame($context['pointIds'][1], $customizedRequestState['id'] ?? null);
        self::assertSame('44.4444567', $customizedRequestState['latitude'] ?? null);
        self::assertSame('4.4444567', $customizedRequestState['longitude'] ?? null);
        self::assertSame('17', $customizedRequestState['accuracy'] ?? null);
        self::assertIsArray($exchange['response'] ?? null);
        self::assertTrue($exchange['response']['success'] ?? false);
        self::assertIsArray($exchange['response']['gpsStates'] ?? null);
        self::assertCount(3, $exchange['response']['gpsStates']);
        $customizedResponseState = $exchange['response']['gpsStates'][2] ?? null;
        self::assertIsArray($customizedResponseState);
        self::assertSame($context['pointIds'][1], $customizedResponseState['id'] ?? null);
        self::assertEqualsWithDelta(44.4444567, $customizedResponseState['latitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta(4.4444567, $customizedResponseState['longitude'] ?? null, 0.000000001);
        self::assertEqualsWithDelta(17.0, $customizedResponseState['accuracy'] ?? null, 0.000000001);
        self::assertFalse($customizedResponseState['inherited'] ?? true);
        $persistedCustomizedGps = $this->storedGpsStates([$context['pointIds'][1]])[$context['pointIds'][1]];
        self::assertEqualsWithDelta(44.4444567, $persistedCustomizedGps['latitude'], 0.000000001);
        self::assertEqualsWithDelta(4.4444567, $persistedCustomizedGps['longitude'], 0.000000001);
        self::assertEqualsWithDelta(17.0, $persistedCustomizedGps['accuracy'], 0.000000001);
        self::assertFalse($persistedCustomizedGps['inherited']);
        self::assertSame($finalOrder, $this->storedPointOrder($context['hikeId']));
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testServerFailureRestoresOrderWithoutOverwritingGpsEditedWhileRequestIsPending(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHikeWithOrderedSteps();
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $context['hikeId']));
        $client->waitFor('[data-route-step-ordering][data-route-step-ordering-ready="true"]');
        $this->prepareUnsavedStepState($webDriver, $context['pointIds'][1], $context['pointMediaId']);
        $webDriver->executeScript(<<<'JS'
            window.__completeRouteStepFailure = null;
            window.fetch = () => new Promise((resolve) => {
                window.__completeRouteStepFailure = () => resolve(new Response(JSON.stringify({
                    success: false,
                    error: 'Echec E2E controle'
                }), {
                    status: 409,
                    headers: { 'Content-Type': 'application/json' }
                }));
            });
        JS);

        $this->clickStepControl($webDriver, $context['pointIds'][2], 'up');
        $transientOrder = [
            $context['pointIds'][0],
            $context['pointIds'][2],
            $context['pointIds'][1],
        ];
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(
            'return typeof window.__completeRouteStepFailure === "function";'
        ));

        $transientState = $this->routeState($webDriver, $context['pointIds'][1], $context['pointMediaId']);
        self::assertSame($transientOrder, $transientState['order']);
        self::assertSame('43.3003', $transientState['latitude']);
        self::assertSame('3.3003', $transientState['longitude']);
        self::assertSame('Enregistrement de l’ordre…', $transientState['status']);
        self::assertTrue($transientState['sameStepNode']);
        self::assertTrue($transientState['sameMediaNode']);

        $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="{$context['pointIds'][1]}"]');
            const latitude = step.querySelector('[data-gps-latitude]');
            const longitude = step.querySelector('[data-gps-longitude]');
            const accuracy = step.querySelector('[data-gps-accuracy]');
            latitude.value = '45.5555123';
            latitude.dispatchEvent(new Event('input', { bubbles: true }));
            longitude.value = '5.5555123';
            longitude.dispatchEvent(new Event('input', { bubbles: true }));
            accuracy.value = '23';
            accuracy.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="{$context['pointIds'][1]}"]');

            return step?.dataset.gpsInherited === 'false'
                && step.dataset.gpsDirty === 'true'
                && step.querySelector('[data-route-step-gps-badge]')?.textContent.includes('GPS personnalisé');
        JS));

        $webDriver->executeScript('window.__completeRouteStepFailure();');
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const order = [...root.querySelectorAll('[data-route-step]')].map((step) => Number(step.dataset.stepId));

            return JSON.stringify(order) === JSON.stringify([{$context['pointIds'][0]}, {$context['pointIds'][1]}, {$context['pointIds'][2]}])
                && root.querySelector('[data-route-step-order-status]')?.textContent.includes('Echec E2E controle');
        JS));

        $restoredState = $this->routeState($webDriver, $context['pointIds'][1], $context['pointMediaId']);
        self::assertSame($context['pointIds'], $restoredState['order']);
        self::assertSame('45.5555123', $restoredState['latitude']);
        self::assertSame('5.5555123', $restoredState['longitude']);
        self::assertSame('23', $restoredState['accuracy']);
        self::assertFalse($restoredState['gpsInherited']);
        self::assertTrue($restoredState['gpsDirty']);
        self::assertSame('0', $restoredState['inheritedInput']);
        self::assertSame('GPS personnalisé', $restoredState['gpsBadge']);
        self::assertSame('Echec E2E controle', $restoredState['status']);
        self::assertFalse($restoredState['duplicateWarningVisible']);
        self::assertTrue($restoredState['sameStepNode']);
        self::assertTrue($restoredState['sameFormNode']);
        self::assertTrue($restoredState['sameMediaNode']);
        self::assertTrue($restoredState['mediaStillNested']);
        self::assertSame('Titre non enregistre E2E', $restoredState['title']);
        self::assertSame('Note non enregistree E2E', $restoredState['note']);
        self::assertSame('Legende media non enregistree E2E', $restoredState['mediaCaption']);
        $unchangedStoredGps = $this->storedGpsStates([$context['pointIds'][1]])[$context['pointIds'][1]];
        self::assertEqualsWithDelta(43.1001, $unchangedStoredGps['latitude'], 0.0000001);
        self::assertEqualsWithDelta(3.1001, $unchangedStoredGps['longitude'], 0.0000001);
        self::assertEqualsWithDelta(5.0, $unchangedStoredGps['accuracy'], 0.0000001);
        self::assertTrue($unchangedStoredGps['inherited']);
        self::assertSame($context['pointIds'], $this->storedPointOrder($context['hikeId']));
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testCopyPreviousOnCustomizedStepRemainsCustomizedAndIsPersistedByNextReorder(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHikeWithOrderedSteps(customizedSecondStep: true);
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $context['hikeId']));
        $client->waitFor('[data-route-step-ordering][data-route-step-ordering-ready="true"]');
        $targetPointId = $context['pointIds'][2];
        $storedBeforeCopy = $this->storedGpsStates([$targetPointId])[$targetPointId];
        self::assertEqualsWithDelta(43.3003, $storedBeforeCopy['latitude'], 0.0000001);
        self::assertEqualsWithDelta(3.3003, $storedBeforeCopy['longitude'], 0.0000001);
        self::assertEqualsWithDelta(9.0, $storedBeforeCopy['accuracy'], 0.0000001);
        self::assertFalse($storedBeforeCopy['inherited']);

        $this->clickCopyPrevious($webDriver, $targetPointId);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="$targetPointId"]');

            return step?.dataset.gpsInherited === 'false'
                && step.dataset.gpsDirty === 'true'
                && step.querySelector('[data-gps-latitude]')?.value === '42.2002'
                && step.querySelector('[data-gps-longitude]')?.value === '2.2002';
        JS));
        $copiedState = $this->stepGpsState($webDriver, $targetPointId);
        self::assertSame('42.2002', $copiedState['latitude']);
        self::assertSame('2.2002', $copiedState['longitude']);
        self::assertSame('7', $copiedState['accuracy']);
        self::assertFalse($copiedState['gpsInherited']);
        self::assertTrue($copiedState['gpsDirty']);
        self::assertSame('0', $copiedState['inheritedInput']);
        self::assertSame('GPS personnalisé', $copiedState['gpsBadge']);
        self::assertTrue($copiedState['duplicateWarningVisible']);

        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            window.__copyPreviousReorderPayload = null;
            window.fetch = async (input, options = {}) => {
                if (String(input).includes('/points/reorder')) {
                    window.__copyPreviousReorderPayload = JSON.parse(options.body);
                }

                return nativeFetch(input, options);
            };
        JS);
        $this->clickStepControl($webDriver, $targetPointId, 'up');
        $expectedOrder = [
            $context['pointIds'][0],
            $context['pointIds'][2],
            $context['pointIds'][1],
        ];
        $this->waitForSavedOrder($webDriver, $expectedOrder);

        $savedState = $this->stepGpsState($webDriver, $targetPointId);
        self::assertSame('42.2002', $savedState['latitude']);
        self::assertSame('2.2002', $savedState['longitude']);
        self::assertSame('7', $savedState['accuracy']);
        self::assertFalse($savedState['gpsInherited']);
        self::assertFalse($savedState['gpsDirty']);
        self::assertSame('0', $savedState['inheritedInput']);
        self::assertSame('GPS personnalisé', $savedState['gpsBadge']);
        self::assertFalse($savedState['duplicateWarningVisible']);
        $payload = $webDriver->executeScript('return window.__copyPreviousReorderPayload;');
        self::assertIsArray($payload);
        self::assertSame($expectedOrder, $payload['orderedIds'] ?? null);
        self::assertIsArray($payload['customizedGps'] ?? null);
        self::assertCount(1, $payload['customizedGps']);
        $customizedGps = $payload['customizedGps'][0] ?? null;
        self::assertIsArray($customizedGps);
        self::assertSame($targetPointId, $customizedGps['id'] ?? null);
        self::assertSame('42.2002', $customizedGps['latitude'] ?? null);
        self::assertSame('2.2002', $customizedGps['longitude'] ?? null);
        self::assertSame('7', $customizedGps['accuracy'] ?? null);
        $storedAfterReorder = $this->storedGpsStates([$targetPointId])[$targetPointId];
        self::assertEqualsWithDelta(42.2002, $storedAfterReorder['latitude'], 0.0000001);
        self::assertEqualsWithDelta(2.2002, $storedAfterReorder['longitude'], 0.0000001);
        self::assertEqualsWithDelta(7.0, $storedAfterReorder['accuracy'], 0.0000001);
        self::assertFalse($storedAfterReorder['inherited']);
        self::assertSame($expectedOrder, $this->storedPointOrder($context['hikeId']));
        $this->assertNoBrowserSevereErrors($client);
    }

    public function testQueuedReordersKeepOnlyLatestCanonicalOrderAndPersistGpsEditedWhileFirstRequestIsPending(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHikeWithOrderedSteps();
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $webDriver);

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit', $context['hikeId']));
        $client->waitFor('[data-route-step-ordering][data-route-step-ordering-ready="true"]');
        $webDriver->executeScript(<<<'JS'
            const nativeFetch = window.fetch.bind(window);
            let reorderCall = 0;
            window.__routeStepQueue = {
                requests: [],
                responses: [],
                releaseFirst: null,
                releaseSecond: null
            };
            window.fetch = (input, options = {}) => {
                if (!String(input).includes('/points/reorder')) {
                    return nativeFetch(input, options);
                }

                const callIndex = reorderCall;
                reorderCall += 1;
                window.__routeStepQueue.requests[callIndex] = JSON.parse(options.body);
                const recordAndResolve = async (responsePromise, resolve, reject) => {
                    try {
                        const response = await responsePromise;
                        window.__routeStepQueue.responses[callIndex] = await response.clone().json();
                        resolve(response);
                    } catch (error) {
                        reject(error);
                    }
                };

                if (callIndex === 0) {
                    return new Promise((resolve, reject) => {
                        window.__routeStepQueue.releaseFirst = () => recordAndResolve(
                            nativeFetch(input, options),
                            resolve,
                            reject
                        );
                    });
                }

                if (callIndex === 1) {
                    const responsePromise = nativeFetch(input, options);

                    return new Promise((resolve, reject) => {
                        window.__routeStepQueue.releaseSecond = () => recordAndResolve(
                            responsePromise,
                            resolve,
                            reject
                        );
                    });
                }

                return nativeFetch(input, options);
            };
        JS);

        $this->clickStepControl($webDriver, $context['pointIds'][2], 'up');
        $firstRequestedOrder = [
            $context['pointIds'][0],
            $context['pointIds'][2],
            $context['pointIds'][1],
        ];
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(
            'return typeof window.__routeStepQueue?.releaseFirst === "function";'
        ));
        self::assertSame($firstRequestedOrder, $webDriver->executeScript(
            'return window.__routeStepQueue.requests[0].orderedIds;'
        ));
        self::assertSame(1, $webDriver->executeScript('return window.__routeStepQueue.requests.length;'));

        $targetPointId = $context['pointIds'][1];
        $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="$targetPointId"]');
            const latitude = step.querySelector('[data-gps-latitude]');
            const longitude = step.querySelector('[data-gps-longitude]');
            const accuracy = step.querySelector('[data-gps-accuracy]');
            latitude.value = '46.6666123';
            latitude.dispatchEvent(new Event('input', { bubbles: true }));
            longitude.value = '6.6666123';
            longitude.dispatchEvent(new Event('input', { bubbles: true }));
            accuracy.value = '31';
            accuracy.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="$targetPointId"]');

            return step?.dataset.gpsInherited === 'false' && step.dataset.gpsDirty === 'true';
        JS));

        $this->clickStepControl($webDriver, $context['pointIds'][0], 'down');
        $latestRequestedOrder = [
            $context['pointIds'][2],
            $context['pointIds'][0],
            $context['pointIds'][1],
        ];
        $queuedState = $this->stepGpsState($webDriver, $targetPointId);
        self::assertSame('46.6666123', $queuedState['latitude']);
        self::assertSame('6.6666123', $queuedState['longitude']);
        self::assertSame('31', $queuedState['accuracy']);
        self::assertFalse($queuedState['gpsInherited']);
        self::assertTrue($queuedState['gpsDirty']);
        self::assertSame($latestRequestedOrder, $webDriver->executeScript(<<<'JS'
            return [...document.querySelectorAll('[data-route-step-list] [data-route-step]')]
                .map((step) => Number(step.dataset.stepId));
        JS));
        self::assertSame(1, $webDriver->executeScript('return window.__routeStepQueue.requests.length;'));

        $webDriver->executeScript(<<<'JS'
            const release = window.__routeStepQueue.releaseFirst;
            window.__routeStepQueue.releaseFirst = null;
            release();
        JS);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<'JS'
            return window.__routeStepQueue.responses.length === 1
                && typeof window.__routeStepQueue.releaseSecond === 'function';
        JS));

        $stateBeforeLatestResponse = $this->stepGpsState($webDriver, $targetPointId);
        self::assertSame('46.6666123', $stateBeforeLatestResponse['latitude']);
        self::assertSame('6.6666123', $stateBeforeLatestResponse['longitude']);
        self::assertSame('31', $stateBeforeLatestResponse['accuracy']);
        self::assertFalse($stateBeforeLatestResponse['gpsInherited']);
        self::assertTrue($stateBeforeLatestResponse['gpsDirty']);
        self::assertSame($latestRequestedOrder, $webDriver->executeScript(<<<'JS'
            return [...document.querySelectorAll('[data-route-step-list] [data-route-step]')]
                .map((step) => Number(step.dataset.stepId));
        JS));
        $queueBeforeLatestResponse = $webDriver->executeScript(<<<'JS'
            return {
                firstResponseOrder: window.__routeStepQueue.responses[0].steps.map((step) => step.id),
                secondRequest: window.__routeStepQueue.requests[1]
            };
        JS);
        self::assertIsArray($queueBeforeLatestResponse);
        self::assertSame($firstRequestedOrder, $queueBeforeLatestResponse['firstResponseOrder'] ?? null);
        self::assertIsArray($queueBeforeLatestResponse['secondRequest'] ?? null);
        self::assertSame($latestRequestedOrder, $queueBeforeLatestResponse['secondRequest']['orderedIds'] ?? null);
        self::assertIsArray($queueBeforeLatestResponse['secondRequest']['customizedGps'] ?? null);
        self::assertCount(1, $queueBeforeLatestResponse['secondRequest']['customizedGps']);
        $queuedGps = $queueBeforeLatestResponse['secondRequest']['customizedGps'][0] ?? null;
        self::assertIsArray($queuedGps);
        self::assertSame($targetPointId, $queuedGps['id'] ?? null);
        self::assertSame('46.6666123', $queuedGps['latitude'] ?? null);
        self::assertSame('6.6666123', $queuedGps['longitude'] ?? null);
        self::assertSame('31', $queuedGps['accuracy'] ?? null);

        $webDriver->executeScript(<<<'JS'
            const release = window.__routeStepQueue.releaseSecond;
            window.__routeStepQueue.releaseSecond = null;
            release();
        JS);
        $this->waitForSavedOrder($webDriver, $latestRequestedOrder);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(
            'return window.__routeStepQueue.responses.length === 2;'
        ));

        $finalState = $this->stepGpsState($webDriver, $targetPointId);
        self::assertSame('46.6666123', $finalState['latitude']);
        self::assertSame('6.6666123', $finalState['longitude']);
        self::assertSame('31', $finalState['accuracy']);
        self::assertFalse($finalState['gpsInherited']);
        self::assertFalse($finalState['gpsDirty']);
        self::assertSame('GPS personnalisé', $finalState['gpsBadge']);
        $latestResponseOrder = $webDriver->executeScript(<<<'JS'
            return window.__routeStepQueue.responses[1].steps.map((step) => step.id);
        JS);
        self::assertSame($latestRequestedOrder, $latestResponseOrder);
        $persistedGps = $this->storedGpsStates([$targetPointId])[$targetPointId];
        self::assertEqualsWithDelta(46.6666123, $persistedGps['latitude'], 0.000000001);
        self::assertEqualsWithDelta(6.6666123, $persistedGps['longitude'], 0.000000001);
        self::assertEqualsWithDelta(31.0, $persistedGps['accuracy'], 0.000000001);
        self::assertFalse($persistedGps['inherited']);
        self::assertSame($latestRequestedOrder, $this->storedPointOrder($context['hikeId']));
        $this->assertNoBrowserSevereErrors($client);
    }

    /**
     * @return array{email: string, password: string, hikeId: int, pointIds: list<int>, pointMediaId: int}
     */
    private function createAdminAndHikeWithOrderedSteps(bool $customizedSecondStep = false): array
    {
        self::bootKernel();
        $container = static::getContainer();
        $rateLimiterCache = $container->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();

        $email = $this->uniqueEmail('route-step-ordering');
        $password = 'E2E Route Step 2026 9!';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('E2E route step '.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setIsVerified(true);

        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $hike = (new HikeDraft())
            ->setTitle('Ordonnancement etapes E2E '.bin2hex(random_bytes(4)))
            ->setSlug('ordonnancement-etapes-e2e-'.bin2hex(random_bytes(5)))
            ->setStatus(HikeDraftStatus::Draft)
            ->setCreatedBy($user);

        $first = (new HikePoint())
            ->setHikeDraft($hike)
            ->setType(HikePointType::Start)
            ->setTitle('Depart E2E')
            ->setLatitude(43.1001)
            ->setLongitude(3.1001)
            ->setAccuracy(5.0)
            ->setPosition(1)
            ->setCoordinatesInherited(false);
        $second = (new HikePoint())
            ->setHikeDraft($hike)
            ->setType(HikePointType::Interest)
            ->setTitle('Etape heritee E2E')
            ->setLatitude($customizedSecondStep ? 42.2002 : 43.1001)
            ->setLongitude($customizedSecondStep ? 2.2002 : 3.1001)
            ->setAccuracy($customizedSecondStep ? 7.0 : 5.0)
            ->setPosition(2)
            ->setCoordinatesInherited(!$customizedSecondStep);
        $third = (new HikePoint())
            ->setHikeDraft($hike)
            ->setType(HikePointType::Viewpoint)
            ->setTitle('Belvedere E2E')
            ->setLatitude(43.3003)
            ->setLongitude(3.3003)
            ->setAccuracy(9.0)
            ->setPosition(3)
            ->setCoordinatesInherited(false);
        foreach ([$first, $second, $third] as $point) {
            $hike->addPoint($point);
        }

        $media = (new MediaAsset())
            ->setUploadedBy($user)
            ->setTitle('Media imbrique E2E')
            ->setCaption('Legende initiale E2E');
        $pointMedia = (new HikePointMedia())
            ->setHikePoint($second)
            ->setMediaAsset($media);
        $second->addMediaLink($pointMedia);

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        foreach ([$user, $hike, $first, $second, $third, $media, $pointMedia] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $hikeId = $hike->getId();
        $firstId = $first->getId();
        $secondId = $second->getId();
        $thirdId = $third->getId();
        $pointMediaId = $pointMedia->getId();
        self::assertIsInt($hikeId);
        self::assertIsInt($firstId);
        self::assertIsInt($secondId);
        self::assertIsInt($thirdId);
        self::assertIsInt($pointMediaId);
        self::ensureKernelShutdown();

        return [
            'email' => $email,
            'password' => $password,
            'hikeId' => $hikeId,
            'pointIds' => [$firstId, $secondId, $thirdId],
            'pointMediaId' => $pointMediaId,
        ];
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

    /** @return array<string, mixed> */
    private function prepareUnsavedStepState(RemoteWebDriver $webDriver, int $pointId, int $pointMediaId): array
    {
        /** @var array<string, mixed> $state */
        $state = $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const step = root.querySelector('[data-route-step][data-step-id="$pointId"]');
            const form = step.querySelector('[data-route-step-form]');
            const media = step.querySelector('#hike-point-media-$pointMediaId');
            window.__routeStepOriginalNode = step;
            window.__routeStepOriginalForm = form;
            window.__routeStepOriginalMedia = media;
            step.querySelector('[data-route-step-title-input]').value = 'Titre non enregistre E2E';
            step.querySelector('textarea[name="note"]').value = 'Note non enregistree E2E';
            media.querySelector('textarea[name="caption"]').value = 'Legende media non enregistree E2E';

            const steps = [...root.querySelectorAll('[data-route-step]')];

            return {
                order: steps.map((item) => Number(item.dataset.stepId)),
                numbers: steps.map((item) => item.querySelector('[data-route-step-number]').textContent.trim()),
                gpsComponentCount: root.querySelectorAll('[data-high-precision-gps]').length,
                gpsComponentsPerStep: steps.map((item) => item.querySelectorAll('[data-high-precision-gps]').length),
                readyGpsComponentCount: root.querySelectorAll('[data-high-precision-gps-ready="true"]').length,
                routeMapCount: root.querySelectorAll('[data-map-container], [data-route-map], [data-route-step-map], .leaflet-container').length,
                duplicateWarningVisible: step.querySelector('[data-route-step-duplicate-warning]').hidden === false,
                firstUpDisabled: steps[0].querySelector('[data-route-step-up]').disabled,
                lastDownDisabled: steps.at(-1).querySelector('[data-route-step-down]').disabled
            };
        JS);

        return $state;
    }

    /** @return array<string, mixed> */
    private function routeState(RemoteWebDriver $webDriver, int $pointId, int $pointMediaId): array
    {
        /** @var array<string, mixed> $state */
        $state = $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const step = root.querySelector('[data-route-step][data-step-id="$pointId"]');
            const form = step.querySelector('[data-route-step-form]');
            const media = step.querySelector('#hike-point-media-$pointMediaId');

            return {
                order: [...root.querySelectorAll('[data-route-step]')].map((item) => Number(item.dataset.stepId)),
                numbers: [...root.querySelectorAll('[data-route-step-number]')].map((item) => item.textContent.trim()),
                insertionPositions: [...root.querySelectorAll('[data-route-step-insert-position]')].map((item) => item.value),
                latitude: step.querySelector('[data-gps-latitude]').value,
                longitude: step.querySelector('[data-gps-longitude]').value,
                accuracy: step.querySelector('[data-gps-accuracy]').value,
                gpsInherited: step.dataset.gpsInherited === 'true',
                gpsDirty: step.dataset.gpsDirty === 'true',
                inheritedInput: step.querySelector('[data-route-step-inherited-input]').value,
                gpsBadge: step.querySelector('[data-route-step-gps-badge]').textContent.trim(),
                duplicateWarningVisible: step.querySelector('[data-route-step-duplicate-warning]').hidden === false,
                status: root.querySelector('[data-route-step-order-status]').textContent.trim(),
                title: step.querySelector('[data-route-step-title-input]').value,
                note: step.querySelector('textarea[name="note"]').value,
                mediaCaption: media.querySelector('textarea[name="caption"]').value,
                sameStepNode: step === window.__routeStepOriginalNode,
                sameFormNode: form === window.__routeStepOriginalForm,
                sameMediaNode: media === window.__routeStepOriginalMedia,
                mediaStillNested: step.contains(media)
            };
        JS);

        return $state;
    }

    /** @return array<string, mixed> */
    private function stepGpsState(RemoteWebDriver $webDriver, int $pointId): array
    {
        /** @var array<string, mixed> $state */
        $state = $webDriver->executeScript(<<<JS
            const step = document.querySelector('[data-route-step][data-step-id="$pointId"]');

            return {
                latitude: step.querySelector('[data-gps-latitude]').value,
                longitude: step.querySelector('[data-gps-longitude]').value,
                accuracy: step.querySelector('[data-gps-accuracy]').value,
                gpsInherited: step.dataset.gpsInherited === 'true',
                gpsDirty: step.dataset.gpsDirty === 'true',
                inheritedInput: step.querySelector('[data-route-step-inherited-input]').value,
                gpsBadge: step.querySelector('[data-route-step-gps-badge]').textContent.trim(),
                duplicateWarningVisible: step.querySelector('[data-route-step-duplicate-warning]').hidden === false
            };
        JS);

        return $state;
    }

    private function clickCopyPrevious(RemoteWebDriver $webDriver, int $pointId): void
    {
        $selector = sprintf('[data-route-step][data-step-id="%d"] [data-route-step-copy-previous]', $pointId);
        $encodedSelector = json_encode($selector, JSON_THROW_ON_ERROR);
        $webDriver->executeScript(<<<JS
            document.querySelector($encodedSelector)?.scrollIntoView({ behavior: 'instant', block: 'center' });
        JS);
        $webDriver->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    private function clickStepControl(RemoteWebDriver $webDriver, int $pointId, string $direction): void
    {
        self::assertContains($direction, ['up', 'down']);
        $selector = sprintf('[data-route-step][data-step-id="%d"] [data-route-step-%s]', $pointId, $direction);
        $encodedSelector = json_encode($selector, JSON_THROW_ON_ERROR);
        $webDriver->executeScript(<<<JS
            document.querySelector($encodedSelector)?.scrollIntoView({ behavior: 'instant', block: 'center' });
        JS);
        $webDriver->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    /** @param list<int> $expectedOrder */
    private function waitForSavedOrder(RemoteWebDriver $webDriver, array $expectedOrder): void
    {
        $expectedJson = json_encode($expectedOrder, JSON_THROW_ON_ERROR);
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<JS
            const root = document.querySelector('[data-route-step-ordering]');
            const order = [...root.querySelectorAll('[data-route-step]')].map((step) => Number(step.dataset.stepId));

            return JSON.stringify(order) === '$expectedJson'
                && root.querySelector('[data-route-step-order-status]')?.textContent.trim() === 'Ordre enregistré';
        JS));
    }

    /** @return list<int> */
    private function storedPointOrder(int $hikeId): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hike = $entityManager->getRepository(HikeDraft::class)->find($hikeId);
        self::assertInstanceOf(HikeDraft::class, $hike);

        $ids = [];
        foreach ($hike->getPoints() as $point) {
            self::assertInstanceOf(HikePoint::class, $point);
            $id = $point->getId();
            self::assertIsInt($id);
            $ids[] = $id;
        }
        self::ensureKernelShutdown();

        return $ids;
    }

    /**
     * @param list<int> $pointIds
     *
     * @return array<int, array{latitude: float|null, longitude: float|null, accuracy: float|null, inherited: bool}>
     */
    private function storedGpsStates(array $pointIds): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $states = [];
        foreach ($pointIds as $pointId) {
            $point = $entityManager->find(HikePoint::class, $pointId);
            self::assertInstanceOf(HikePoint::class, $point);
            $states[$pointId] = [
                'latitude' => $point->getLatitude(),
                'longitude' => $point->getLongitude(),
                'accuracy' => $point->getAccuracy(),
                'inherited' => $point->hasInheritedCoordinates(),
            ];
        }
        self::ensureKernelShutdown();

        return $states;
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
