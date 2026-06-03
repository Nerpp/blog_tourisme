<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Security\Voter\AdminAccessVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AdminAccessIntegrationTest extends IntegrationTestCase
{
    public function testVerifiedAdminIsGrantedAdminAccessThroughContainerService(): void
    {
        $vote = $this->voter()->vote($this->tokenFor($this->createUser(['ROLE_ADMIN'], true)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    public function testUnverifiedAdminIsDeniedAdminAccessThroughContainerService(): void
    {
        $vote = $this->voter()->vote($this->tokenFor($this->createUser(['ROLE_ADMIN'], false)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testRegularUserIsDeniedAdminAccessThroughContainerService(): void
    {
        $vote = $this->voter()->vote($this->tokenFor($this->createUser(['ROLE_USER'], true)), null, [AdminAccessVoter::ACCESS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    private function voter(): AdminAccessVoter
    {
        $voter = $this->service(AdminAccessVoter::class);
        self::assertInstanceOf(AdminAccessVoter::class, $voter);

        return $voter;
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
