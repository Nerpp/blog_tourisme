<?php

namespace App\Tests\E2E;

use App\Entity\User;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase as BasePantherTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[SkipDatabaseRollback]
abstract class PantherTestCase extends BasePantherTestCase
{
    protected static function createBrowser(): Client
    {
        self::configureBrowserEnvironment();
        $profileDirectory = sys_get_temp_dir().'/panther-profile-'.bin2hex(random_bytes(8));
        if (!is_dir($profileDirectory)) {
            mkdir($profileDirectory, 0700, true);
        }

        return self::createPantherClient([
            'browser' => self::CHROME,
            'browser_arguments' => [
                '--headless=new',
                '--no-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--user-data-dir='.$profileDirectory,
                '--window-size=1400,1000',
            ],
            'env' => [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '1',
                'DATABASE_URL_TEST' => $_SERVER['DATABASE_URL_TEST'] ?? 'mysql://app:app@mysql:3306/app_test?serverVersion=8.0&charset=utf8mb4',
            ],
        ]);
    }

    private static function configureBrowserEnvironment(): void
    {
        $environment = [
            'HOME' => '/tmp',
            'XDG_CACHE_HOME' => '/tmp/panther-cache',
            'XDG_CONFIG_HOME' => '/tmp/panther-config',
            'PANTHER_CHROME_BINARY' => '/usr/bin/chromium',
        ];

        foreach ($environment as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * @param list<string> $roles
     */
    protected function createVerifiedUser(string $email, string $plainPassword, array $roles = ['ROLE_USER']): User
    {
        self::bootKernel();
        $container = static::getContainer();
        $rateLimiterCache = $container->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('E2E '.bin2hex(random_bytes(5)))
            ->setRoles($roles)
            ->setIsVerified(true);

        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();

        self::ensureKernelShutdown();

        return $user;
    }

    protected function uniqueEmail(string $prefix): string
    {
        return sprintf('%s-%s@blog-tourisme.test', $prefix, bin2hex(random_bytes(6)));
    }

    protected function assertPageHasBuiltAssets(Client $client, string ...$entries): void
    {
        $renderedAssetUrls = $this->renderedBuildAssetUrls($client->getWebDriver());

        foreach ($entries as $entry) {
            foreach ($this->manifestStyleUrls($entry) as $assetUrl) {
                self::assertContains($assetUrl, $renderedAssetUrls, sprintf('Expected asset "%s" for entry "%s".', $assetUrl, $entry));
            }

            $assetUrl = $this->manifestScriptUrl($entry);
            if ($assetUrl !== null) {
                self::assertContains($assetUrl, $renderedAssetUrls, sprintf('Expected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }

        self::assertSame(
            $renderedAssetUrls,
            array_values(array_unique($renderedAssetUrls)),
            'Rendered Vite asset URLs must not be duplicated.',
        );
    }

    protected function assertPageHasBuiltStyles(Client $client, string ...$entries): void
    {
        $renderedAssetUrls = $this->renderedBuildAssetUrls($client->getWebDriver());

        foreach ($entries as $entry) {
            foreach ($this->manifestStyleUrls($entry) as $assetUrl) {
                self::assertContains($assetUrl, $renderedAssetUrls, sprintf('Expected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }

        self::assertSame(
            $renderedAssetUrls,
            array_values(array_unique($renderedAssetUrls)),
            'Rendered Vite asset URLs must not be duplicated.',
        );
    }

    protected function assertPageHasBuiltScripts(Client $client, string ...$entries): void
    {
        $renderedAssetUrls = $this->renderedBuildAssetUrls($client->getWebDriver());

        foreach ($entries as $entry) {
            $assetUrl = $this->manifestScriptUrl($entry);
            if ($assetUrl !== null) {
                self::assertContains($assetUrl, $renderedAssetUrls, sprintf('Expected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }

        self::assertSame(
            $renderedAssetUrls,
            array_values(array_unique($renderedAssetUrls)),
            'Rendered Vite asset URLs must not be duplicated.',
        );
    }

    protected function assertPageDoesNotHaveBuiltScripts(Client $client, string ...$entries): void
    {
        $renderedAssetUrls = $this->renderedBuildAssetUrls($client->getWebDriver());

        foreach ($entries as $entry) {
            $assetUrl = $this->manifestScriptUrl($entry);
            if ($assetUrl !== null) {
                self::assertNotContains($assetUrl, $renderedAssetUrls, sprintf('Unexpected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }
    }

    protected function assertPageDoesNotHaveBuiltAssets(Client $client, string ...$entries): void
    {
        $renderedAssetUrls = $this->renderedBuildAssetUrls($client->getWebDriver());

        foreach ($entries as $entry) {
            foreach ($this->manifestStyleUrls($entry) as $assetUrl) {
                self::assertNotContains($assetUrl, $renderedAssetUrls, sprintf('Unexpected asset "%s" for entry "%s".', $assetUrl, $entry));
            }

            $assetUrl = $this->manifestScriptUrl($entry);
            if ($assetUrl !== null) {
                self::assertNotContains($assetUrl, $renderedAssetUrls, sprintf('Unexpected asset "%s" for entry "%s".', $assetUrl, $entry));
            }
        }
    }

    protected function assertNoBrowserSevereErrors(Client $client): void
    {
        $errors = array_filter(
            $client->getWebDriver()->manage()->getLog('browser'),
            static fn (array $entry): bool => ($entry['level'] ?? '') === 'SEVERE',
        );

        self::assertSame([], $errors);
    }

    /**
     * @return list<string>
     */
    private function renderedBuildAssetUrls(RemoteWebDriver $webDriver): array
    {
        $urls = [];

        foreach ($webDriver->findElements(WebDriverBy::cssSelector('link[href], script[src]')) as $element) {
            $url = $element->getAttribute('href') ?: $element->getAttribute('src');
            $path = is_string($url) ? (string) parse_url($url, PHP_URL_PATH) : '';

            if (str_starts_with($path, '/build/')) {
                $urls[] = $path;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function manifestStyleUrls(string $entry): array
    {
        $chunk = $this->manifest()[$entry] ?? null;
        self::assertIsArray($chunk, sprintf('Missing Vite manifest entry "%s".', $entry));

        $urls = [];

        foreach ($chunk['css'] ?? [] as $cssFile) {
            self::assertIsString($cssFile);
            $urls[] = '/build/'.$cssFile;
        }

        return $urls;
    }

    private function manifestScriptUrl(string $entry): ?string
    {
        $chunk = $this->manifest()[$entry] ?? null;
        self::assertIsArray($chunk, sprintf('Missing Vite manifest entry "%s".', $entry));

        if (!isset($chunk['file'])) {
            return null;
        }

        self::assertIsString($chunk['file']);

        return '/build/'.$chunk['file'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        static $manifest = null;

        if (is_array($manifest)) {
            return $manifest;
        }

        $manifestPath = dirname(__DIR__, 2).'/public/build/manifest.json';
        self::assertFileExists($manifestPath, 'Run "docker compose run --rm node npm run build" before Panther tests.');

        $decoded = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $manifest = $decoded;
    }
}
