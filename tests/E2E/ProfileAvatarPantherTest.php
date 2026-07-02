<?php

namespace App\Tests\E2E;

use App\Entity\User;
use App\Service\AvatarUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Symfony\Component\Panther\Client;

final class ProfileAvatarPantherTest extends PantherTestCase
{
    /** @var list<string> */
    private array $uploadedAvatars = [];

    protected function tearDown(): void
    {
        if ($this->uploadedAvatars !== []) {
            self::bootKernel();
            $avatarUploadService = static::getContainer()->get(AvatarUploadService::class);
            self::assertInstanceOf(AvatarUploadService::class, $avatarUploadService);

            foreach (array_unique($this->uploadedAvatars) as $avatarPath) {
                $avatarUploadService->delete($avatarPath);
            }

            self::ensureKernelShutdown();
        }

        parent::tearDown();
    }

    public function testProfileAvatarShowsLocalPreviewThenDisplaysUploadedAvatar(): void
    {
        $this->skipIfFrontendBuildIsMissing();
        $this->requireGdForProfileAvatar();

        $email = $this->uniqueEmail('profile-avatar');
        $password = 'E2E Profile Avatar 2026 9!';
        $user = $this->createVerifiedUser($email, $password);
        $userId = $user->getId();
        self::assertIsInt($userId);

        $imagePath = $this->createAvatarFixture();

        try {
            $client = $this->loginAsUser($email, $password);
            $webDriver = $client->getWebDriver();
            $client->request('GET', '/profile');
            $client->waitFor('[data-avatar-preview-input][data-avatar-preview-ready="true"]');

            /** @var array{visibleImages: int, visibleInitials: int, messageHidden: bool} $initialState */
            $initialState = $webDriver->executeScript(<<<'JS'
                return {
                    visibleImages: Array.from(document.querySelectorAll('[data-avatar-preview-image]'))
                        .filter((image) => !image.classList.contains('is-hidden')).length,
                    visibleInitials: Array.from(document.querySelectorAll('[data-avatar-preview-initials]'))
                        .filter((initials) => !initials.classList.contains('is-hidden')).length,
                    messageHidden: document.querySelector('[data-avatar-preview-message]')?.classList.contains('is-hidden') === true,
                };
            JS);

            self::assertSame(0, $initialState['visibleImages']);
            self::assertSame(2, $initialState['visibleInitials']);
            self::assertTrue($initialState['messageHidden']);

            $webDriver
                ->findElement(WebDriverBy::cssSelector('[data-avatar-preview-input]'))
                ->sendKeys($imagePath);

            /** @var array{visibleImages: int, hiddenInitials: int, previewSources: list<string>, messageVisible: bool} $previewState */
            $previewState = (new WebDriverWait($webDriver, 8))->until(static function (RemoteWebDriver $webDriver): array|false {
                $state = $webDriver->executeScript(<<<'JS'
                    const previewImages = Array.from(document.querySelectorAll('[data-avatar-preview-image]'));
                    const initials = Array.from(document.querySelectorAll('[data-avatar-preview-initials]'));

                    return {
                        visibleImages: previewImages.filter((image) => !image.classList.contains('is-hidden')).length,
                        hiddenInitials: initials.filter((initial) => initial.classList.contains('is-hidden')).length,
                        previewSources: previewImages.map((image) => image.getAttribute('src') || ''),
                        messageVisible: document.querySelector('[data-avatar-preview-message]')?.classList.contains('is-hidden') === false,
                    };
                JS);

                if (!is_array($state) || $state['visibleImages'] !== 2 || $state['hiddenInitials'] !== 2 || $state['messageVisible'] !== true) {
                    return false;
                }

                return $state;
            });

            self::assertNotSame([], $previewState['previewSources']);
            foreach ($previewState['previewSources'] as $previewSource) {
                self::assertStringStartsWith('blob:', $previewSource);
            }

            $webDriver->findElement(WebDriverBy::cssSelector('.profile-actions .profile-button'))->click();
            $client->waitFor('.flash-success');

            self::assertStringContainsString('/profile', $client->getCurrentURL());
            self::assertSelectorTextContains('.flash-success', 'Votre profil a été mis à jour.');

            $avatarPath = $this->avatarPathForUser($userId);
            self::assertIsString($avatarPath);
            $this->uploadedAvatars[] = $avatarPath;
            self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $avatarPath);

            /** @var array{visibleImages: int, visibleInitials: int, avatarPaths: list<string>, messageHidden: bool} $storedState */
            $storedState = $webDriver->executeScript(<<<'JS'
                const previewImages = Array.from(document.querySelectorAll('[data-avatar-preview-image]'));

                return {
                    visibleImages: previewImages.filter((image) => !image.classList.contains('is-hidden')).length,
                    visibleInitials: Array.from(document.querySelectorAll('[data-avatar-preview-initials]'))
                        .filter((initials) => !initials.classList.contains('is-hidden')).length,
                    avatarPaths: previewImages.map((image) => new URL(image.currentSrc || image.src, location.href).pathname),
                    messageHidden: document.querySelector('[data-avatar-preview-message]')?.classList.contains('is-hidden') === true,
                };
            JS);

            self::assertSame(2, $storedState['visibleImages']);
            self::assertSame(0, $storedState['visibleInitials']);
            self::assertSame([$avatarPath, $avatarPath], $storedState['avatarPaths']);
            self::assertTrue($storedState['messageHidden']);
            $this->assertNoBrowserSevereErrors($client);
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }

            $storedAvatarPath = $this->avatarPathForUser($userId);
            if (is_string($storedAvatarPath) && !in_array($storedAvatarPath, $this->uploadedAvatars, true)) {
                $this->uploadedAvatars[] = $storedAvatarPath;
            }
        }
    }

    private function loginAsUser(string $email, string $password): Client
    {
        $client = self::createBrowser();
        $client->request('GET', '/login');

        self::assertSelectorIsVisible('form.login-form');

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('_username'))->sendKeys($email);
        $webDriver->findElement(WebDriverBy::name('_password'))->sendKeys($password);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.logout-form');

        return $client;
    }

    private function createAvatarFixture(): string
    {
        $path = sys_get_temp_dir().'/panther-profile-avatar-'.bin2hex(random_bytes(6)).'.png';
        $image = imagecreatetruecolor(128, 96);
        self::assertNotFalse($image);

        $background = imagecolorallocate($image, 32, 111, 84);
        $accent = imagecolorallocate($image, 238, 246, 239);
        self::assertNotFalse($background);
        self::assertNotFalse($accent);

        imagefilledrectangle($image, 0, 0, 127, 95, $background);
        imagefilledellipse($image, 64, 48, 56, 56, $accent);
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);

        return $path;
    }

    private function avatarPathForUser(int $userId): ?string
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $user = $entityManager->find(User::class, $userId);
        self::ensureKernelShutdown();

        return $user instanceof User ? $user->getAvatarPath() : null;
    }

    private function requireGdForProfileAvatar(): void
    {
        foreach (['imagecreatetruecolor', 'imagecolorallocate', 'imagefilledrectangle', 'imagefilledellipse', 'imagepng', 'imagecreatefrompng', 'imagewebp'] as $function) {
            if (!function_exists($function)) {
                self::markTestSkipped('GD PNG/WebP support is required for the profile avatar Panther test.');
            }
        }
    }

    private function skipIfFrontendBuildIsMissing(): void
    {
        if (!is_file(dirname(__DIR__, 2).'/public/build/manifest.json')) {
            self::markTestSkipped('Run docker compose run --rm node npm run build before this Panther test.');
        }
    }
}
