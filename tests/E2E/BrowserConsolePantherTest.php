<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;

final class BrowserConsolePantherTest extends PantherTestCase
{
    public function testApplicationReferenceErrorStillFailsTheGlobalBrowserAssertion(): void
    {
        $client = self::createBrowser();
        $client->request('GET', '/');
        $client->waitFor('body');
        $this->assertNoBrowserSevereErrors($client);

        $webDriver = $client->getWebDriver();
        $probeUrl = $webDriver->executeScript(<<<'JS'
            const probeUrl = new URL('/panther-local-application-probe.js', window.location.origin).toString();
            const script = document.createElement('script');
            script.textContent = `document.documentElement.dataset.pantherJsErrorProbe = 'done'; writeEmbed();\n//# sourceURL=${probeUrl}`;
            document.head.appendChild(script);

            return probeUrl;
        JS);
        self::assertIsString($probeUrl);
        $client->waitFor('html[data-panther-js-error-probe="done"]');

        $failure = null;
        try {
            $this->assertNoBrowserSevereErrors($client);
        } catch (AssertionFailedError $exception) {
            $failure = $exception;
        }

        $severeEntries = $this->severeEntriesFromFailure($failure);
        $message = $severeEntries[0]['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString($probeUrl, $message);
        self::assertStringContainsString('Uncaught ReferenceError: writeEmbed is not defined', $message);
    }

    public function testDifferentThirdPartySevereErrorIsNotIgnored(): void
    {
        $message = 'https://www.youtube-nocookie.com/embed/test 1:1 Uncaught TypeError: unexpected third-party failure';
        $failure = null;

        try {
            $this->assertNoSevereBrowserLogEntries([[
                'level' => 'SEVERE',
                'message' => $message,
                'source' => 'javascript',
                'timestamp' => 1,
            ]]);
        } catch (AssertionFailedError $exception) {
            $failure = $exception;
        }

        $severeEntries = $this->severeEntriesFromFailure($failure);
        self::assertSame($message, $severeEntries[0]['message'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function severeEntriesFromFailure(?AssertionFailedError $failure): array
    {
        self::assertInstanceOf(ExpectationFailedException::class, $failure);
        $comparisonFailure = $failure->getComparisonFailure();
        self::assertNotNull($comparisonFailure);
        $actual = $comparisonFailure->getActual();
        self::assertIsArray($actual);

        return array_values($actual);
    }
}
