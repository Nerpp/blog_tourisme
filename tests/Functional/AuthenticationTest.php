<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticationTest extends WebTestCase
{
    public function testLoginWithPersistedUserWorks(): void
    {
        $client = static::createClient();
        $email = sprintf('login-%s@example.test', bin2hex(random_bytes(6)));
        $password = 'Valid login password 123';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Login Test '.bin2hex(random_bytes(4)))
            ->setRoles(['ROLE_USER'])
            ->setIsVerified(true);

        $container = static::getContainer();
        $rateLimiterCache = $container->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();

        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();

        $crawler = $client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/');
    }

    public function testLoggedInUserIsRedirectedAwayFromLoginPage(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail(sprintf('already-logged-%s@example.test', $token))
            ->setDisplayName(sprintf('Already Logged %s', $token))
            ->setPassword('x')
            ->setRoles(['ROLE_USER'])
            ->setIsVerified(true);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);

        $client->request('GET', '/login');

        self::assertResponseRedirects('/profile');
    }
}
