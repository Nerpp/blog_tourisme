<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LighthouseUrlsCommandTest extends KernelTestCase
{
    public function testJsonListsCanonicalPublicUrlsOnly(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command = $application->find('app:lighthouse:urls');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--format' => 'json',
            '--limit-per-type' => '1',
        ]);

        self::assertSame(0, $exitCode);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(11, $payload['total'] ?? null);
        $entries = $payload['urls'] ?? null;
        self::assertIsArray($entries);

        $urls = [];
        $types = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            $id = $entry['id'] ?? null;
            $type = $entry['type'] ?? null;
            $title = $entry['title'] ?? null;
            $url = $entry['url'] ?? null;
            self::assertIsString($id);
            self::assertIsString($type);
            self::assertIsString($title);
            self::assertIsString($url);

            $urls[] = $url;
            $types[] = $type;
            self::assertStringStartsWith('/', $url);
            self::assertDoesNotMatchRegularExpression('#^/(?:admin|login|register|reset-password|profile)(?:/|$)#', $url);
            self::assertStringNotContainsString('brouillon', strtolower($title));
        }

        self::assertSame($urls, array_values(array_unique($urls)));
        foreach (['/', '/articles', '/destinations', '/randonnees', '/visites', '/places'] as $requiredUrl) {
            self::assertContains($requiredUrl, $urls);
        }

        foreach (['home', 'listing', 'destination', 'article', 'hike', 'city-visit', 'place'] as $requiredType) {
            self::assertContains($requiredType, $types);
        }
    }
}
