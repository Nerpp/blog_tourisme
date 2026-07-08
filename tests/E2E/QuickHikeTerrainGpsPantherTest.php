<?php

namespace App\Tests\E2E;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\User;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class QuickHikeTerrainGpsPantherTest extends PantherTestCase
{
    public function testStartButtonGetsGpsAndCreatesStartPoint(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHike();
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();

        $client->request('GET', sprintf('/admin/quick-hike/%d', $context['hikeId']));
        self::assertSelectorTextContains('body', 'Enregistrer le point de départ');
        self::assertSelectorTextNotContains('body', 'Ajouter le point d’intérêt');
        $this->mockGeolocationSuccess($webDriver, 42.7123456, 2.9123456, 8);

        $webDriver->findElement(WebDriverBy::cssSelector('[data-terrain-gps-submit]'))->click();

        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(<<<'JS'
            const text = document.body.textContent || '';
            return text.includes('42.712346') && text.includes('2.912346');
        JS));
        $point = $this->latestHikePoint($context['hikeId']);

        self::assertInstanceOf(HikePoint::class, $point);
        self::assertSame(HikePointType::Start, $point->getType());
        self::assertEqualsWithDelta(42.7123456, $point->getLatitude(), 0.0000001);
        self::assertEqualsWithDelta(2.9123456, $point->getLongitude(), 0.0000001);
        self::assertEqualsWithDelta(8.0, $point->getAccuracy(), 0.0000001);
    }

    public function testInterestButtonGetsGpsAndCreatesInterestPointAfterStart(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHike(withStartPoint: true);
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();

        $client->request('GET', sprintf('/admin/quick-hike/%d', $context['hikeId']));
        self::assertSelectorTextContains('body', 'Ajouter le point d’intérêt');
        self::assertSelectorTextNotContains('body', 'Enregistrer le point de départ');
        $webDriver->executeScript(<<<'JS'
            document.querySelector('#quick_hike_point_type').value = 'viewpoint';
            document.querySelector('#quick_hike_point_type').dispatchEvent(new Event('change', { bubbles: true }));
        JS);
        $webDriver->findElement(WebDriverBy::cssSelector('#quick_hike_point_title'))->sendKeys('Belvédère E2E');
        $this->mockGeolocationSuccess($webDriver, 42.7134567, 2.9134567, 11);

        $webDriver->findElement(WebDriverBy::cssSelector('[data-terrain-gps-submit]'))->click();

        $this->waitUntil($webDriver, static fn () => str_contains((string) $webDriver->executeScript('return document.body.textContent;'), 'Belvédère E2E'));
        $point = $this->latestHikePoint($context['hikeId']);

        self::assertInstanceOf(HikePoint::class, $point);
        self::assertSame(HikePointType::Viewpoint, $point->getType());
        self::assertSame('Belvédère E2E', $point->getTitle());
        self::assertEqualsWithDelta(42.7134567, $point->getLatitude(), 0.0000001);
        self::assertEqualsWithDelta(2.9134567, $point->getLongitude(), 0.0000001);
        self::assertEqualsWithDelta(11.0, $point->getAccuracy(), 0.0000001);
    }

    public function testPermissionDeniedKeepsTerrainFormOnPage(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndHike();
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();

        $client->request('GET', sprintf('/admin/quick-hike/%d', $context['hikeId']));
        $this->mockGeolocationPermissionDenied($webDriver);

        $webDriver->findElement(WebDriverBy::cssSelector('[data-terrain-gps-submit]'))->click();

        $this->waitUntil($webDriver, static fn () => str_contains((string) $webDriver->executeScript('return document.querySelector("[data-gps-status]")?.textContent || "";'), 'La localisation est refusée.'));
        $state = $webDriver->executeScript(<<<'JS'
            return {
                submitted: window.__terrainGpsSubmitted === true,
                latitude: document.querySelector('[data-gps-latitude]')?.value || '',
                longitude: document.querySelector('[data-gps-longitude]')?.value || '',
                buttonDisabled: document.querySelector('[data-terrain-gps-submit]')?.disabled === true
            };
        JS);

        self::assertIsArray($state);
        self::assertFalse($state['submitted']);
        self::assertSame('', $state['latitude']);
        self::assertSame('', $state['longitude']);
        self::assertFalse($state['buttonDisabled']);
        self::assertNull($this->latestHikePoint($context['hikeId']));
    }

    /**
     * @return array{email: string, password: string, hikeId: int}
     */
    private function createAdminAndHike(bool $withStartPoint = false): array
    {
        self::bootKernel();
        $container = static::getContainer();
        $rateLimiterCache = $container->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();

        $email = $this->uniqueEmail('quick-hike-gps');
        $password = 'E2E Quick Hike GPS 2026 9!';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('E2E quick hike gps '.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setIsVerified(true);

        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $hike = (new HikeDraft())
            ->setTitle('Randonnée terrain GPS E2E '.bin2hex(random_bytes(4)))
            ->setSlug('randonnee-terrain-gps-e2e-'.bin2hex(random_bytes(5)))
            ->setStatus(HikeDraftStatus::Draft)
            ->setCreatedBy($user);

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->persist($hike);

        if ($withStartPoint) {
            $startPoint = (new HikePoint())
                ->setHikeDraft($hike)
                ->setType(HikePointType::Start)
                ->setTitle('Départ E2E')
                ->setLatitude(42.7000)
                ->setLongitude(2.9000)
                ->setPosition(1);
            $hike->addPoint($startPoint);
            $entityManager->persist($startPoint);
        }

        $entityManager->flush();
        $hikeId = $hike->getId();
        self::assertIsInt($hikeId);
        self::ensureKernelShutdown();

        return [
            'email' => $email,
            'password' => $password,
            'hikeId' => $hikeId,
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

    private function mockGeolocationSuccess(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, float $latitude, float $longitude, int $accuracy): void
    {
        $webDriver->executeScript(<<<JS
            Object.defineProperty(navigator, 'geolocation', {
                configurable: true,
                value: {
                    getCurrentPosition: (success, error, options) => {
                        window.__terrainGpsOptions = options;
                        success({
                            coords: {
                                latitude: $latitude,
                                longitude: $longitude,
                                accuracy: $accuracy
                            }
                        });
                    }
                }
            });
        JS);
    }

    private function mockGeolocationPermissionDenied(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver): void
    {
        $webDriver->executeScript(<<<'JS'
            window.__terrainGpsSubmitted = false;
            HTMLFormElement.prototype.submit = function () {
                window.__terrainGpsSubmitted = true;
            };
            Object.defineProperty(navigator, 'geolocation', {
                configurable: true,
                value: {
                    getCurrentPosition: (success, error) => {
                        error({ code: 1, PERMISSION_DENIED: 1 });
                    }
                }
            });
        JS);
    }

    private function latestHikePoint(int $hikeId): ?HikePoint
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $point = $entityManager->getRepository(HikePoint::class)->findOneBy(
            ['hikeDraft' => $hikeId],
            ['position' => 'DESC', 'id' => 'DESC']
        );
        self::ensureKernelShutdown();

        return $point instanceof HikePoint ? $point : null;
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
