<?php

namespace App\Tests\Unit;

use App\Command\BootstrapProductionAccessCommand;
use App\Entity\AdminRoleAudit;
use App\Entity\ProductionAccessBootstrap;
use App\Entity\User;
use App\Repository\ProductionAccessBootstrapRepository;
use App\Repository\UserRepository;
use App\Service\UserRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class BootstrapProductionAccessCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testSimulationDoesNotWriteAnything(): void
    {
        $user = $this->user('admin@example.test');
        $superAdmin = $this->user('super@example.test')->setRoles(['ROLE_SUPER_ADMIN']);
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([
            'admin@example.test' => $user,
            'super@example.test' => $superAdmin,
        ], null, $persisted, $transactions);

        $status = $tester->execute(['--file' => $this->yaml("super_admins:\n  - super@example.test\nadmins:\n  - admin@example.test\n")]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNotContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertStringContainsString('Aucune donnée n’a été modifiée', $tester->getDisplay());
    }

    public function testApplyAddsRoleMarkerAndBootstrapAudit(): void
    {
        $user = $this->user('admin@example.test');
        $superAdmin = $this->user('super@example.test')->setRoles(['ROLE_SUPER_ADMIN']);
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([
            'admin@example.test' => $user,
            'super@example.test' => $superAdmin,
        ], null, $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins:\n  - super@example.test\nadmins:\n  - admin@example.test\n"),
            '--apply' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertSame(1, $transactions);
        self::assertCount(2, $persisted);
        self::assertInstanceOf(AdminRoleAudit::class, $persisted[0]);
        self::assertSame(AdminRoleAudit::SOURCE_BOOTSTRAP, $persisted[0]->getSource());
        self::assertInstanceOf(ProductionAccessBootstrap::class, $persisted[1]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $persisted[1]->getConfigurationFingerprint());
    }

    public function testMissingUserFailsWithoutModification(): void
    {
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([], null, $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins:\n  - absent@example.test\nadmins: []\n"),
            '--apply' => true,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertStringContainsString('Introuvable', $tester->getDisplay());
        self::assertStringContainsString('Aucune donnée n’a été modifiée', $tester->getDisplay());
    }

    public function testUnverifiedUserFailsWithoutModification(): void
    {
        $user = $this->user('super@example.test', false);
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester(['super@example.test' => $user], null, $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins:\n  - super@example.test\nadmins: []\n"),
            '--apply' => true,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNotContains(UserRoleManager::ROLE_SUPER_ADMIN, $user->getRoles());
        self::assertSame(0, $transactions);
        self::assertStringContainsString('non vérifié', $tester->getDisplay());
    }

    public function testSimulationRejectsConfigurationWithoutDeclaredSuperAdmin(): void
    {
        $existingSuperAdmin = $this->user('existing-super@example.test')->setRoles(['ROLE_SUPER_ADMIN']);
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester(['existing-super@example.test' => $existingSuperAdmin], null, $persisted, $transactions);

        $status = $tester->execute(['--file' => $this->yaml("super_admins: []\nadmins: []\n")]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertStringContainsString('exige au moins un super-administrateur', $tester->getDisplay());
        self::assertStringContainsString('Aucune donnée n’a été modifiée', $tester->getDisplay());
    }

    public function testApplyWithoutDeclaredSuperAdminNeverCreatesMarker(): void
    {
        $admin = $this->user('admin@example.test');
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester(['admin@example.test' => $admin], null, $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins: []\nadmins:\n  - admin@example.test\n"),
            '--apply' => true,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNotContains(UserRoleManager::ROLE_ADMIN, $admin->getRoles());
        self::assertSame([], $persisted);
        self::assertSame(0, $transactions);
        self::assertStringContainsString('« super_admins »', $tester->getDisplay());
    }

    public function testInvalidYamlFailsClearly(): void
    {
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([], null, $persisted, $transactions);

        $status = $tester->execute(['--file' => $this->yaml("super_admins: [\nadmins: []\n")]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('YAML est invalide', $tester->getDisplay());
        self::assertSame(0, $transactions);
    }

    public function testDuplicateEmailFailsClearly(): void
    {
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([], null, $persisted, $transactions);

        $status = $tester->execute(['--file' => $this->yaml("super_admins: []\nadmins:\n  - admin@example.test\n  - ADMIN@example.test\n")]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('apparaît plusieurs fois', $tester->getDisplay());
    }

    public function testUnknownOrSensitiveKeyIsRejected(): void
    {
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([], null, $persisted, $transactions);

        $status = $tester->execute(['--file' => $this->yaml("super_admins: []\nadmins: []\npassword: forbidden\n")]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('seules clés autorisées', $tester->getDisplay());
        self::assertSame(0, $transactions);
    }

    public function testEmailInBothListsFailsClearly(): void
    {
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([], null, $persisted, $transactions);

        $status = $tester->execute(['--file' => $this->yaml("super_admins:\n  - admin@example.test\nadmins:\n  - admin@example.test\n")]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('deux', $tester->getDisplay());
    }

    public function testSecondRunIsRejectedWithoutForce(): void
    {
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([], new ProductionAccessBootstrap(), $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins: []\nadmins: []\n"),
            '--apply' => true,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('déjà été effectué', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(0, $transactions);
    }

    public function testForceAllowsExplicitAdditiveRerunWithoutNewMarker(): void
    {
        $user = $this->user('admin@example.test');
        $superAdmin = $this->user('super@example.test')->setRoles(['ROLE_SUPER_ADMIN']);
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([
            'admin@example.test' => $user,
            'super@example.test' => $superAdmin,
        ], new ProductionAccessBootstrap(), $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins:\n  - super@example.test\nadmins:\n  - admin@example.test\n"),
            '--apply' => true,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains(UserRoleManager::ROLE_ADMIN, $user->getRoles());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(AdminRoleAudit::class, $persisted[0]);
        self::assertSame(1, $transactions);
    }

    public function testExistingRoleIsNotDuplicatedOrAudited(): void
    {
        $user = $this->user('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $superAdmin = $this->user('super@example.test')->setRoles(['ROLE_SUPER_ADMIN']);
        $persisted = [];
        $transactions = 0;
        $tester = $this->tester([
            'admin@example.test' => $user,
            'super@example.test' => $superAdmin,
        ], null, $persisted, $transactions);

        $status = $tester->execute([
            '--file' => $this->yaml("super_admins:\n  - super@example.test\nadmins:\n  - admin@example.test\n"),
            '--apply' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, count(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => $role === UserRoleManager::ROLE_ADMIN,
        )));
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ProductionAccessBootstrap::class, $persisted[0]);
        self::assertStringContainsString('Rôle déjà présent', $tester->getDisplay());
    }

    /**
     * @param array<string, User> $users
     * @param list<object>        $persisted
     */
    private function tester(
        array $users,
        ?ProductionAccessBootstrap $marker,
        array &$persisted,
        int &$transactions,
    ): CommandTester {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findOneByEmail')->willReturnCallback(
            static fn (string $email): ?User => $users[$email] ?? null,
        );

        $bootstrapRepository = $this->createMock(ProductionAccessBootstrapRepository::class);
        $bootstrapRepository->method('findCompleted')->willReturn($marker);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use ($entityManager, &$transactions): mixed {
                ++$transactions;

                return $callback($entityManager);
            },
        );

        $roleManager = new UserRoleManager($entityManager, $userRepository);
        $command = new BootstrapProductionAccessCommand(
            $userRepository,
            $bootstrapRepository,
            $roleManager,
            $entityManager,
            '/tmp',
        );

        return new CommandTester($command);
    }

    private function yaml(string $contents): string
    {
        $file = tempnam('/tmp', 'initial-admins-test-');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    private function user(string $email, bool $verified = true): User
    {
        return (new User())
            ->setEmail($email)
            ->setDisplayName('Commande test '.bin2hex(random_bytes(3)))
            ->setPassword('test-password')
            ->setRoles(['ROLE_USER'])
            ->setIsVerified($verified);
    }
}
