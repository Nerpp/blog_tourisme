<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\AvatarUploadService;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ProfileControllerTest extends FunctionalTestCase
{
    /** @var list<string> */
    private array $uploadedAvatars = [];

    protected function tearDown(): void
    {
        foreach ($this->uploadedAvatars as $avatarPath) {
            $this->avatarUploadService()->delete($avatarPath);
        }

        parent::tearDown();
    }

    public function testAnonymousVisitorIsRedirectedFromPrivateProfile(): void
    {
        $client = static::createClient();

        $client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
    }

    public function testLoggedInUserCanOpenPrivateProfileWithAvatarFallback(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $displayName = 'Nom fonctionnel '.$this->uniqueToken('profile');
        $client->loginUser($user);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $user->getEmail());
        self::assertSelectorTextContains('body', 'Initiale par défaut');
    }

    public function testPublicProfileDisplaysAvatarPathWhenPresent(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/test-avatar.png');
        $this->persistAndFlush($user);

        $client->request('GET', sprintf('/users/%d', $user->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $user->getDisplayName());
        self::assertStringContainsString('/uploads/avatars/test-avatar.png', $client->getResponse()->getContent() ?: '');
    }

    public function testPrivateProfileDisplaysAvatarPathWhenPresent(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/private-avatar.webp');
        $this->persistAndFlush($user);
        $client->loginUser($user);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/avatars/private-avatar.webp', $client->getResponse()->getContent() ?: '');
    }

    public function testConnectedHeaderDisplaysAvatarWhenPresent(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/header-avatar.webp');
        $this->persistAndFlush($user);
        $client->loginUser($user);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/avatars/header-avatar.webp', $client->getResponse()->getContent() ?: '');
    }

    public function testProfileDisplayNameCanBeUpdatedWithValidCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $displayName = 'Nom fonctionnel '.$this->uniqueToken('profile');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => $displayName,
                'receivePublicationEmails' => '1',
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertSame($displayName, $user->getDisplayName());
    }

    public function testProfileCanReplaceAvatarWithValidPng(): void
    {
        $this->requireGdFor('png');

        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/previous-avatar.webp');
        $this->persistAndFlush($user);
        $displayName = 'Avatar Valide '.$this->uniqueToken('profile');
        $avatar = $this->createImage('png', 320, 180);
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/profile');
            self::assertResponseIsSuccessful();
            $form = $crawler->selectButton('Enregistrer les modifications')->form([
                'profile_form[displayName]' => $displayName,
                'profile_form[receivePublicationEmails]' => '1',
            ]);
            $form['profile_form[avatarFile]']->upload($avatar);

            $client->submit($form);
        } finally {
            if (is_file($avatar)) {
                unlink($avatar);
            }
        }

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertSame($displayName, $user->getDisplayName());
        $avatarPath = $user->getAvatarPath();
        self::assertIsString($avatarPath);
        $this->uploadedAvatars[] = $avatarPath;
        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $avatarPath);

        $storedPath = (string) static::getContainer()->getParameter('kernel.project_dir').'/public'.$avatarPath;
        self::assertFileExists($storedPath);
        $dimensions = getimagesize($storedPath);
        self::assertIsArray($dimensions);
        self::assertSame([256, 256], [$dimensions[0], $dimensions[1]]);
    }

    public function testProfileRejectsTooSmallAvatarWithoutChangingExistingAvatar(): void
    {
        $this->requireGdFor('png');

        $client = static::createClient();
        $user = $this->createUser();
        $previousAvatarPath = '/uploads/avatars/existing-avatar.webp';
        $user->setAvatarPath($previousAvatarPath);
        $this->persistAndFlush($user);
        $avatar = $this->createImage('png', 32, 80);
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/profile');
            self::assertResponseIsSuccessful();
            $form = $crawler->selectButton('Enregistrer les modifications')->form([
                'profile_form[displayName]' => (string) $user->getDisplayName(),
            ]);
            $form['profile_form[avatarFile]']->upload($avatar);

            $client->submit($form);
        } finally {
            if (is_file($avatar)) {
                unlink($avatar);
            }
        }

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'L’image de profil doit mesurer au moins 64 px de côté.',
            $client->getResponse()->getContent() ?: '',
        );
        self::assertSame($previousAvatarPath, $this->refresh($user)->getAvatarPath());
    }

    public function testProfileDisplayNameAtCurrentLengthBoundaryIsPersisted(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $displayName = substr('Nom '.$this->uniqueToken('profile'), 0, 120);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => $displayName,
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertSame($displayName, $user->getDisplayName());
    }

    public function testInvalidProfileDataDoesNotOverwritePersistedValues(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $userId = $user->getId();
        $originalDisplayName = $user->getDisplayName();
        $user->setReceivePublicationEmails(true);
        $this->persistAndFlush($user);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile');

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => str_repeat('Nom invalide ', 20),
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame($originalDisplayName, $storedUser->getDisplayName());
        self::assertTrue($storedUser->isReceivePublicationEmails());
    }

    public function testProfileRejectsPrivilegedFieldsWithoutChangingAccountState(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $userId = $user->getId();
        $originalDisplayName = $user->getDisplayName();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile');

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => 'Nom injecté '.$this->uniqueToken('profile'),
                'roles' => ['ROLE_ADMIN'],
                'isVerified' => '1',
                'isBanned' => '1',
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame($originalDisplayName, $storedUser->getDisplayName());
        self::assertSame(['ROLE_USER'], $storedUser->getRoles());
        self::assertTrue($storedUser->isVerified());
        self::assertFalse($storedUser->isBanned());
    }

    public function testDeleteAvatarRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/test-avatar.png');
        $this->persistAndFlush($user);
        $client->loginUser($user);
        $client->catchExceptions(false);

        $this->expectException(AccessDeniedException::class);

        $client->request('POST', '/profile', [
            '_profile_action' => 'delete_avatar',
            '_token' => 'bad-token',
        ]);
    }

    public function testDeleteAvatarWithValidCsrfClearsAvatarPath(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/test-avatar.png');
        $this->persistAndFlush($user);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/profile', [
            '_profile_action' => 'delete_avatar',
            '_token' => $this->inputValue($crawler, 'form#profile-avatar-delete-form input[name="_token"]'),
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertNull($user->getAvatarPath());
    }

    private function avatarUploadService(): AvatarUploadService
    {
        $service = static::getContainer()->get(AvatarUploadService::class);
        self::assertInstanceOf(AvatarUploadService::class, $service);

        return $service;
    }

    private function createImage(string $extension, int $width, int $height): string
    {
        $this->requireGdFor($extension);

        $path = sprintf('%s/profile-avatar-%s.%s', sys_get_temp_dir(), bin2hex(random_bytes(6)), $extension);
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 48, 120, 180));

        match ($extension) {
            'jpg' => imagejpeg($image, $path),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            default => false,
        } || self::fail(sprintf('Unable to create %s fixture image.', $extension));

        imagedestroy($image);

        return $path;
    }

    private function requireGdFor(string $extension): void
    {
        $required = match ($extension) {
            'jpg' => 'imagejpeg',
            'png' => 'imagepng',
            'webp' => 'imagewebp',
            default => null,
        };

        if (!function_exists('imagecreatetruecolor') || ($required !== null && !function_exists($required))) {
            self::markTestSkipped(sprintf('GD %s support is required for this profile test.', strtoupper($extension)));
        }
    }
}
