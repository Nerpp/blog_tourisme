<?php

namespace App\Tests\Unit;

use App\Command\GrantAdminRoleCommand;
use App\Entity\AdminRoleAudit;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class GrantAdminRoleCommandTest extends TestCase
{
    public function testInvalidEmailIsRejectedBeforeLookup(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::never())->method('findOneByEmail');
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester(null, 'prod', $persisted, $transactions, $flushes, $userRepository);

        $status = $tester->execute(['email' => ' adresse-invalide ']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Adresse e-mail invalide', $tester->getDisplay());
        self::assertStringContainsString('Aucune donnée n’a été modifiée', $tester->getDisplay());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testExecutionOutsideProdIsRejectedBeforeLookup(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::never())->method('findOneByEmail');
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester(null, 'test', $persisted, $transactions, $flushes, $userRepository);

        $status = $tester->execute(['email' => 'user@example.test', '--apply' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('réservée à l’environnement prod', $tester->getDisplay());
        self::assertStringContainsString('Aucune donnée n’a été modifiée', $tester->getDisplay());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testUnknownUserIsRejected(): void
    {
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester(null, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'absent@example.test', '--apply' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Aucun utilisateur trouvé', $tester->getDisplay());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testUnverifiedUserIsRejected(): void
    {
        $user = $this->user('user@example.test', false);
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'user@example.test', '--apply' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('n’est pas vérifiée', $tester->getDisplay());
        self::assertNotContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testBannedUserIsRejected(): void
    {
        $user = $this->user('user@example.test')->setIsBanned(true);
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'user@example.test', '--apply' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('est banni', $tester->getDisplay());
        self::assertNotContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testSuperAdminIsRejectedWithoutExplicitAdminRole(): void
    {
        $user = $this->user('super@example.test')->setRoles([UserRoleManager::ROLE_SUPER_ADMIN]);
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'super@example.test', '--apply' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('hiérarchie des rôles', $tester->getDisplay());
        self::assertNotContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testExistingAdminReturnsSuccessWithoutAudit(): void
    {
        $user = $this->user('admin@example.test')->setRoles([UserRoleManager::ROLE_ADMIN]);
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'admin@example.test', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('est déjà administrateur', $tester->getDisplay());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testValidSimulationNormalizesEmailAndDoesNotModifyData(): void
    {
        $user = $this->user('user@example.test');
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())
            ->method('findOneByEmail')
            ->with('user@example.test')
            ->willReturn($user);
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes, $userRepository);

        $status = $tester->execute(['email' => '  USER@EXAMPLE.TEST  ']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Utilisateur : user@example.test', $tester->getDisplay());
        self::assertStringContainsString('État : trouvé et vérifié', $tester->getDisplay());
        self::assertStringContainsString('Action : ROLE_ADMIN serait ajouté', $tester->getDisplay());
        self::assertStringContainsString('Aucune donnée n’a été modifiée.', $tester->getDisplay());
        self::assertNotContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertSame(0, $flushes);
    }

    public function testApplyAddsAdminRoleInOneTransaction(): void
    {
        $user = $this->user('user@example.test');
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'user@example.test', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame(1, $transactions);
        self::assertSame(1, $flushes);
        self::assertStringContainsString('ROLE_ADMIN a été ajouté', $tester->getDisplay());
    }

    public function testApplyKeepsEveryExistingRole(): void
    {
        $user = $this->user('editor@example.test')->setRoles(['ROLE_EDITOR', 'ROLE_MODERATOR']);
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'editor@example.test', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertContains('ROLE_EDITOR', $user->getRoles());
        self::assertContains('ROLE_MODERATOR', $user->getRoles());
        self::assertSame(1, $transactions);
        self::assertSame(1, $flushes);
    }

    public function testApplyCreatesExactlyOneCliAudit(): void
    {
        $user = $this->user('user@example.test');
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        $status = $tester->execute(['email' => 'user@example.test', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $persisted);
        self::assertInstanceOf(AdminRoleAudit::class, $persisted[0]);
        self::assertSame(AdminRoleAudit::ACTION_GRANT, $persisted[0]->getAction());
        self::assertSame(UserRoleManager::ROLE_ADMIN, $persisted[0]->getRole());
        self::assertSame(AdminRoleAudit::SOURCE_CLI, $persisted[0]->getSource());
        self::assertNull($persisted[0]->getActor());
        self::assertSame($user, $persisted[0]->getTargetUser());
    }

    public function testSecondApplyIsIdempotentWithoutNewAudit(): void
    {
        $user = $this->user('user@example.test');
        $persisted = [];
        $transactions = 0;
        $flushes = 0;
        $tester = $this->tester($user, 'prod', $persisted, $transactions, $flushes);

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'user@example.test', '--apply' => true]));
        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'user@example.test', '--apply' => true]));

        self::assertCount(1, $persisted);
        self::assertSame(1, $transactions);
        self::assertSame(1, $flushes);
        self::assertSame(1, count(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => $role === UserRoleManager::ROLE_ADMIN,
        )));
        self::assertStringContainsString('est déjà administrateur', $tester->getDisplay());
    }

    /** @param list<object> $persisted */
    private function tester(
        ?User $user,
        string $environment,
        array &$persisted,
        int &$transactions,
        int &$flushes,
        ?UserRepository $userRepository = null,
    ): CommandTester {
        $userRepository ??= $this->createMock(UserRepository::class);
        if ($userRepository instanceof \PHPUnit\Framework\MockObject\MockObject) {
            $userRepository->method('findOneByEmail')->willReturn($user);
        }

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->method('flush')->willReturnCallback(
            static function () use (&$flushes): void {
                ++$flushes;
            },
        );
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use ($entityManager, &$transactions): mixed {
                ++$transactions;

                return $callback($entityManager);
            },
        );

        $roleManager = new UserRoleManager($entityManager, $userRepository);

        return new CommandTester(new GrantAdminRoleCommand(
            $userRepository,
            $roleManager,
            $entityManager,
            $environment,
        ));
    }

    private function user(string $email, bool $verified = true): User
    {
        return (new User())
            ->setEmail($email)
            ->setDisplayName('Commande CLI '.bin2hex(random_bytes(3)))
            ->setPassword('test-password')
            ->setRoles(['ROLE_USER'])
            ->setIsVerified($verified);
    }
}
