<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\Voter\AdminAccessVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AdminAccessVoterTest extends TestCase
{
    public function testVerifiedAdminCanAccessAdmin(): void
    {
        $voter = new AdminAccessVoter();

        $vote = $voter->vote($this->tokenFor($this->user(['ROLE_ADMIN'], true)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    public function testVerifiedSuperAdminInheritsAdminAccess(): void
    {
        $voter = new AdminAccessVoter();

        $vote = $voter->vote($this->tokenFor($this->user(['ROLE_SUPER_ADMIN'], true)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    public function testUnverifiedAdminCannotAccessAdmin(): void
    {
        $voter = new AdminAccessVoter();

        $vote = $voter->vote($this->tokenFor($this->user(['ROLE_ADMIN'], false)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testRegularVerifiedUserCannotAccessAdmin(): void
    {
        $voter = new AdminAccessVoter();

        $vote = $voter->vote($this->tokenFor($this->user(['ROLE_USER'], true)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles, bool $verified): User
    {
        return (new User())
            ->setEmail(sprintf('%s@example.test', strtolower($roles[0])))
            ->setDisplayName('Utilisateur test')
            ->setPassword('test-password')
            ->setRoles($roles)
            ->setIsVerified($verified);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
