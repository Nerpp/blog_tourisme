<?php

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase as BasePantherTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class PantherTestCase extends BasePantherTestCase
{
    protected static function createBrowser(): Client
    {
        self::configureBrowserEnvironment();

        return self::createPantherClient([
            'browser' => self::CHROME,
            'browser_arguments' => [
                '--headless=new',
                '--no-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
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

    protected function createVerifiedUser(string $email, string $plainPassword): User
    {
        self::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('E2E '.bin2hex(random_bytes(5)))
            ->setRoles(['ROLE_USER'])
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
}
