<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\Voter\UserRoleManagementVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class UserRoleManagementVoterTest extends TestCase
{
    public function testOnlyVerifiedSuperAdminCanManageRoles(): void
    {
        $voter = new UserRoleManagementVoter();
        $target = $this->user(['ROLE_USER']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(
            $this->tokenFor($this->user(['ROLE_SUPER_ADMIN'])),
            $target,
            [UserRoleManagementVoter::MANAGE],
        ));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(
            $this->tokenFor($this->user(['ROLE_ADMIN'])),
            $target,
            [UserRoleManagementVoter::MANAGE],
        ));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(
            $this->tokenFor($this->user(['ROLE_SUPER_ADMIN'], false)),
            $target,
            [UserRoleManagementVoter::MANAGE],
        ));
    }

    /** @param list<string> $roles */
    private function user(array $roles, bool $verified = true): User
    {
        return (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Voter test')
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
