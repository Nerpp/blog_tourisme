<?php

namespace App\Tests\Integration;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        static::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
    }

    protected function service(string $id): object
    {
        return static::getContainer()->get($id);
    }

    protected function uniqueToken(string $prefix): string
    {
        return sprintf('%s-%s', $prefix, bin2hex(random_bytes(6)));
    }

    /**
     * @param list<string> $roles
     */
    protected function createUser(array $roles = ['ROLE_USER'], bool $verified = true): User
    {
        $token = $this->uniqueToken('user');

        return (new User())
            ->setEmail(sprintf('%s@example.test', $token))
            ->setDisplayName(sprintf('Integration %s', $token))
            ->setPassword('integration-password')
            ->setRoles($roles)
            ->setIsVerified($verified);
    }
}
