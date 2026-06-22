<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\AvatarUploadService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationControllerTest extends FunctionalTestCase
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

    public function testRegistrationWithoutAvatarPersistsHashedPasswordAndLoginWorks(): void
    {
        $client = static::createClient();
        $email = sprintf('register-no-avatar-%s@example.test', bin2hex(random_bytes(6)));
        $password = 'Phrase robuste inscription 2026 9!';

        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('input[name="registration_form[_token]"]')->count());

        $client->submit($this->registrationForm($crawler, $email, 'Sans Avatar '.$this->uniqueToken('register'), $password));

        self::assertResponseRedirects('/login');

        $user = $this->registeredUser($email);
        self::assertNull($user->getAvatarPath());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isVerified());
        self::assertNotSame($password, $user->getPassword());
        self::assertTrue($this->passwordHasher()->isPasswordValid($user, $password));

        $this->loginWithPassword($client, $email, $password);
    }

    public function testRegistrationRejectsPrivilegedFieldsWithoutCreatingAccount(): void
    {
        $client = static::createClient();
        $email = sprintf('register-privileged-%s@example.test', bin2hex(random_bytes(6)));
        $crawler = $client->request('GET', '/register');

        $client->request('POST', '/register', [
            'registration_form' => [
                'email' => $email,
                'displayName' => 'Privilège refusé '.$this->uniqueToken('register'),
                'plainPassword' => [
                    'first' => 'Phrase robuste privilège 2026 9!',
                    'second' => 'Phrase robuste privilège 2026 9!',
                ],
                'roles' => ['ROLE_ADMIN'],
                'isVerified' => '1',
                '_token' => $this->inputValue($crawler, 'input[name="registration_form[_token]"]'),
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    public function testAuthenticatedUserIsRedirectedAwayFromRegistration(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/register');

        self::assertResponseRedirects('/profile');
    }

    public function testRegistrationWithAvatarPersistsAvatarPathAndLoginWorks(): void
    {
        $this->requireGdFor('png');

        $client = static::createClient();
        $email = sprintf('register-avatar-%s@example.test', bin2hex(random_bytes(6)));
        $password = 'Phrase robuste avatar 2026 9!';
        $avatar = $this->createImage('png', 320, 180);

        $crawler = $client->request('GET', '/register');
        $form = $this->registrationForm($crawler, $email, 'Avec Avatar '.$this->uniqueToken('register'), $password);
        $form['registration_form[avatarFile]']->upload($avatar);

        $client->submit($form);

        self::assertResponseRedirects('/login');

        $user = $this->registeredUser($email);
        $avatarPath = $user->getAvatarPath();
        self::assertIsString($avatarPath);
        $this->uploadedAvatars[] = $avatarPath;
        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $avatarPath);
        self::assertFileExists((string) static::getContainer()->getParameter('kernel.project_dir').'/public'.$avatarPath);
        self::assertTrue($this->passwordHasher()->isPasswordValid($user, $password));

        $this->loginWithPassword($client, $email, $password);
    }

    public function testShortNumericPasswordIsRejectedWithClearMessage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $client->submit($this->registrationForm(
            $crawler,
            sprintf('register-short-password-%s@example.test', bin2hex(random_bytes(6))),
            'Mot Court '.$this->uniqueToken('register'),
            '12345678',
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Le mot de passe doit contenir au moins 12 caractères.');
    }

    public function testCommonPasswordIsRejectedWithClearMessage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $client->submit($this->registrationForm(
            $crawler,
            sprintf('register-common-password-%s@example.test', bin2hex(random_bytes(6))),
            'Mot Commun '.$this->uniqueToken('register'),
            'password123456',
        ));

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent() ?: '';
        self::assertTrue(
            str_contains($content, 'Votre mot de passe est trop faible.')
            || str_contains($content, 'Ce mot de passe est connu dans des fuites de données.'),
            'The registration page should display a clear weak or compromised password error.',
        );
    }

    private function registrationForm(Crawler $crawler, string $email, string $displayName, string $password): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->selectButton('Créer mon compte')->form([
            'registration_form[email]' => $email,
            'registration_form[displayName]' => $displayName,
            'registration_form[plainPassword][first]' => $password,
            'registration_form[plainPassword][second]' => $password,
        ]);
    }

    private function registeredUser(string $email): User
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function loginWithPassword(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->request('POST', '/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $this->inputValue($crawler, 'input[name="_csrf_token"]'),
        ]);

        self::assertResponseRedirects('/');
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        return $passwordHasher;
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

        $path = sprintf('%s/registration-avatar-%s.%s', sys_get_temp_dir(), bin2hex(random_bytes(6)), $extension);
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
            self::markTestSkipped(sprintf('GD %s support is required for this registration test.', strtoupper($extension)));
        }
    }
}
