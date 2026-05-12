<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class BannedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->checkBannedUser($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->checkBannedUser($user);
    }

    private function checkBannedUser(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isBanned() || in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        throw new CustomUserMessageAccountStatusException('Votre compte est suspendu. Vous ne pouvez plus publier de commentaire.');
    }
}
