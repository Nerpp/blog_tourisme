<?php

namespace App\Tests\E2E;

use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\User;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HikeStudioLocationPickerPantherTest extends PantherTestCase
{
    public function testExistingHikeCommuneCanBeReplacedThroughLocationPicker(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $context = $this->createAdminAndLocatedHike();
        $client = $this->loginAsAdmin($context['email'], $context['password']);
        $webDriver = $client->getWebDriver();
        $pickerSelector = sprintf('#studio-hike-location-%d', $context['hikeId']);
        $narbonne = [
            'nom' => 'Narbonne E2E',
            'code' => $context['narbonneCode'],
            'codesPostaux' => ['11100'],
            'centre' => ['coordinates' => [3.0031000, 43.1843000]],
            'departement' => ['code' => '11', 'nom' => 'Aude'],
            'region' => ['nom' => 'Occitanie'],
        ];

        $client->request('GET', sprintf('/admin/studio/hikes/%d/edit?e2e_frontend_assets=1', $context['hikeId']));
        self::assertSelectorTextContains('body', 'Beziers E2E');
        self::assertSame('Beziers E2E', $this->pickerValue($webDriver, $pickerSelector, 'detectedCommuneName'));
        self::assertSame($context['beziersCode'], $this->pickerValue($webDriver, $pickerSelector, 'detectedCommuneCode'));
        self::assertSame('studio-hike-main-form', $this->pickerFormAttribute($webDriver, $pickerSelector, 'detectedCommuneName'));
        self::assertSame('studio-hike-main-form', $this->pickerFormOwner($webDriver, $pickerSelector, 'detectedCommuneName'));
        $this->mockCommuneSearch($webDriver, $narbonne);

        $this->scrollIntoView($webDriver, $pickerSelector.' [data-commune-edit]');
        $webDriver->findElement(WebDriverBy::cssSelector($pickerSelector.' [data-commune-edit]'))->click();
        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(
            "return document.querySelector('$pickerSelector [name=\"detectedCommuneName\"]')?.value === ''
                && document.querySelector('$pickerSelector [name=\"detectedCommuneCode\"]')?.value === ''
                && document.querySelector('$pickerSelector [name=\"locationLatitude\"]')?.value === ''
                && document.querySelector('$pickerSelector [data-map-panel]')?.hidden === true
                && document.querySelector('$pickerSelector [data-validate-point]')?.disabled === true;"
        ));

        $webDriver->findElement(WebDriverBy::cssSelector($pickerSelector.' [data-commune-search-input]'))->sendKeys('Narbonne');
        $client->waitFor($pickerSelector.' [data-commune-search-results] button');
        $webDriver->findElement(WebDriverBy::cssSelector($pickerSelector.' [data-commune-search-results] button'))->click();

        $this->waitUntil($webDriver, static fn () => (bool) $webDriver->executeScript(
            "return document.querySelector('$pickerSelector [name=\"detectedCommuneName\"]')?.value === 'Narbonne E2E'
                && document.querySelector('$pickerSelector [name=\"detectedCommuneCode\"]')?.value === '{$context['narbonneCode']}'
                && document.querySelector('$pickerSelector [data-validate-point]')?.disabled === false;"
        ));

        self::assertSame('Narbonne E2E', $this->pickerValue($webDriver, $pickerSelector, 'detectedCommuneName'));
        self::assertSame($context['narbonneCode'], $this->pickerValue($webDriver, $pickerSelector, 'detectedCommuneCode'));
        self::assertNotSame('Beziers E2E', $this->pickerValue($webDriver, $pickerSelector, 'detectedCommuneName'));
        /** @var array<string, string> $payloadBeforeSubmit */
        $payloadBeforeSubmit = $webDriver->executeScript(<<<'JS'
            return Object.fromEntries(new FormData(document.getElementById('studio-hike-main-form')).entries());
        JS);
        self::assertSame('Narbonne E2E', $payloadBeforeSubmit['detectedCommuneName'] ?? null);
        self::assertSame($context['narbonneCode'], $payloadBeforeSubmit['detectedCommuneCode'] ?? null);

        $this->clickMapAndValidatePoint($webDriver, $pickerSelector);
        $this->waitUntil($webDriver, static fn () => !str_contains($webDriver->getCurrentURL(), 'e2e_frontend_assets'));
        $this->waitUntil($webDriver, static fn () => str_contains((string) $webDriver->executeScript('return document.body.textContent;'), 'La randonnée rapide a été enregistrée.'));
        $this->waitUntil($webDriver, static fn () => str_contains((string) $webDriver->executeScript('return document.body.textContent;'), 'Narbonne E2E'));
        $this->assertStoredHikeLocationWasReplaced($context);
    }

    /**
     * @return array{email: string, password: string, hikeId: int, beziersId: int, narbonneId: int, beziersCode: string, narbonneCode: string}
     */
    private function createAdminAndLocatedHike(): array
    {
        self::bootKernel();
        $container = static::getContainer();

        $email = $this->uniqueEmail('hike-studio-location');
        $password = 'E2E Studio Location 2026 9!';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('E2E studio location '.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setIsVerified(true);

        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $country = (new Destination())->setName('France E2E')->setSlug('france-e2e-'.bin2hex(random_bytes(5)))->setType(DestinationType::Country)->setCode('FR');
        $region = (new Destination())->setName('Occitanie E2E')->setSlug('occitanie-e2e-'.bin2hex(random_bytes(5)))->setType(DestinationType::Region)->setParent($country)->setCode('76');
        $herault = (new Destination())->setName('Herault E2E')->setSlug('herault-e2e-'.bin2hex(random_bytes(5)))->setType(DestinationType::Department)->setParent($region)->setCode('34');
        $aude = (new Destination())->setName('Aude E2E')->setSlug('aude-e2e-'.bin2hex(random_bytes(5)))->setType(DestinationType::Department)->setParent($region)->setCode('11');
        $beziersCode = '34'.(string) random_int(100, 999);
        $narbonneCode = '11'.(string) random_int(100, 999);
        $beziers = (new Destination())->setName('Beziers E2E')->setSlug('beziers-e2e-'.bin2hex(random_bytes(5)))->setType(DestinationType::City)->setParent($herault)->setCode($beziersCode)->setLatitude(43.3476)->setLongitude(3.2190);
        $narbonne = (new Destination())->setName('Narbonne E2E')->setSlug('narbonne-e2e-'.bin2hex(random_bytes(5)))->setType(DestinationType::City)->setParent($aude)->setCode($narbonneCode)->setLatitude(43.1843)->setLongitude(3.0031);
        $hike = (new HikeDraft())
            ->setTitle('Randonnée remplacement commune E2E '.bin2hex(random_bytes(4)))
            ->setSlug('randonnee-remplacement-commune-e2e-'.bin2hex(random_bytes(5)))
            ->setStatus(HikeDraftStatus::Draft)
            ->setCreatedBy($user)
            ->setDestination($beziers)
            ->setGeographicDestination($beziers)
            ->setDetectedCommuneName('Beziers E2E')
            ->setDetectedCommuneCode($beziersCode)
            ->setDetectedDepartmentName('Herault E2E')
            ->setDetectedRegionName('Occitanie E2E');
        $point = (new HikePoint())
            ->setHikeDraft($hike)
            ->setType(HikePointType::Start)
            ->setTitle('Départ Beziers E2E')
            ->setPosition(1)
            ->setLatitude(43.3442)
            ->setLongitude(3.2158)
            ->setAccuracy(7.0)
            ->setDetectedCommuneName('Beziers E2E')
            ->setDetectedCommuneCode($beziersCode)
            ->setDetectedDepartmentName('Herault E2E')
            ->setDetectedRegionName('Occitanie E2E');
        $hike->addPoint($point);

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        foreach ([$user, $country, $region, $herault, $aude, $beziers, $narbonne, $hike, $point] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $hikeId = $hike->getId();
        $beziersId = $beziers->getId();
        $narbonneId = $narbonne->getId();
        self::assertIsInt($hikeId);
        self::assertIsInt($beziersId);
        self::assertIsInt($narbonneId);
        self::ensureKernelShutdown();

        return [
            'email' => $email,
            'password' => $password,
            'hikeId' => $hikeId,
            'beziersId' => $beziersId,
            'narbonneId' => $narbonneId,
            'beziersCode' => $beziersCode,
            'narbonneCode' => $narbonneCode,
        ];
    }

    private function loginAsAdmin(string $email, string $password): \Symfony\Component\Panther\Client
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

    private function clickMapAndValidatePoint(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, string $pickerSelector): void
    {
        $webDriver->executeScript(<<<JS
            const map = document.querySelector('$pickerSelector [data-map-container]');
            const rect = map.getBoundingClientRect();
            map.dispatchEvent(new MouseEvent('click', {
                clientX: rect.left + rect.width * 0.57,
                clientY: rect.top + rect.height * 0.43,
                bubbles: true,
                cancelable: true,
                view: window
            }));
        JS);
        $this->scrollIntoView($webDriver, $pickerSelector.' [data-validate-point]');
        $webDriver->findElement(WebDriverBy::cssSelector($pickerSelector.' [data-validate-point]'))->click();
    }

    private function scrollIntoView(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, string $selector): void
    {
        $encodedSelector = json_encode($selector, JSON_THROW_ON_ERROR);
        $webDriver->executeScript(<<<JS
            document
                .querySelector($encodedSelector)
                ?.scrollIntoView({ behavior: 'instant', block: 'center', inline: 'center' });
        JS);
    }

    private function pickerValue(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, string $pickerSelector, string $name): string
    {
        return (string) $webDriver->executeScript(
            "return document.querySelector('$pickerSelector [name=\"$name\"]')?.value || '';"
        );
    }

    private function pickerFormAttribute(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, string $pickerSelector, string $name): string
    {
        return (string) $webDriver->executeScript(
            "return document.querySelector('$pickerSelector [name=\"$name\"]')?.getAttribute('form') || '';"
        );
    }

    private function pickerFormOwner(\Facebook\WebDriver\Remote\RemoteWebDriver $webDriver, string $pickerSelector, string $name): string
    {
        return (string) $webDriver->executeScript(
            "return document.querySelector('$pickerSelector [name=\"$name\"]')?.form?.id || '';"
        );
    }

    /**
     * @param array{hikeId: int, beziersId: int, narbonneId: int, narbonneCode: string} $context
     */
    private function assertStoredHikeLocationWasReplaced(array $context): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hike = $entityManager->getRepository(HikeDraft::class)->find($context['hikeId']);
        self::assertInstanceOf(HikeDraft::class, $hike);
        $point = $hike->getPoints()->first();
        self::assertInstanceOf(HikePoint::class, $point);

        self::assertSame($context['narbonneId'], $hike->getDestination()?->getId());
        self::assertSame($context['narbonneId'], $hike->getGeographicDestination()?->getId());
        self::assertNotSame($context['beziersId'], $hike->getDestination()?->getId());
        self::assertSame('Narbonne E2E', $hike->getDetectedCommuneName());
        self::assertSame($context['narbonneCode'], $hike->getDetectedCommuneCode());
        self::assertSame('Narbonne E2E', $point->getDetectedCommuneName());
        self::assertSame($context['narbonneCode'], $point->getDetectedCommuneCode());
        self::assertNotEqualsWithDelta(43.3442, $point->getLatitude(), 0.0000001);
        self::assertNotEqualsWithDelta(3.2158, $point->getLongitude(), 0.0000001);
        self::ensureKernelShutdown();
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
