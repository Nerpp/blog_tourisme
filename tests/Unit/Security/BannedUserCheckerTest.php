<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\BannedUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class BannedUserCheckerTest extends TestCase
{
    public function testRegularBannedUserIsRejectedBeforeAuthentication(): void
    {
        $checker = new BannedUserChecker();

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('security.account.suspended');

        $checker->checkPreAuth($this->user(['ROLE_USER'], banned: true));
    }

    public function testRegularBannedUserIsRejectedAfterAuthentication(): void
    {
        $checker = new BannedUserChecker();

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('security.account.suspended');

        $checker->checkPostAuth($this->user(['ROLE_USER'], banned: true));
    }

    public function testUnbannedUserAndBannedAdminAreAccepted(): void
    {
        $checker = new BannedUserChecker();

        $checker->checkPreAuth($this->user(['ROLE_USER'], banned: false));
        $checker->checkPreAuth($this->user(['ROLE_ADMIN'], banned: true));

        self::addToAssertionCount(2);
    }

    public function testNonApplicationUserIsIgnored(): void
    {
        (new BannedUserChecker())->checkPreAuth(new InMemoryUser('external@example.test', 'password'));

        self::addToAssertionCount(1);
    }

    /** @param list<string> $roles */
    private function user(array $roles, bool $banned): User
    {
        return (new User())
            ->setEmail(strtolower(str_replace('ROLE_', '', $roles[0])).'-banned-checker@example.test')
            ->setDisplayName('Utilisateur bannissement')
            ->setPassword('test-password')
            ->setRoles($roles)
            ->setIsBanned($banned);
    }
}
