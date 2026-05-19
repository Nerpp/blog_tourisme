<?php

namespace App\Security\Voter;

use App\Entity\CityVisitPoint;
use App\Entity\HikePoint;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class GpsAccessVoter extends Voter
{
    public const GPS_ACCESS = 'GPS_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::GPS_ACCESS
            && (
                $subject instanceof HikePoint
                || $subject instanceof CityVisitPoint
            );
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return true;
    }
}
