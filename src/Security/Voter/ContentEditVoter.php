<?php

namespace App\Security\Voter;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\Place;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Article|Place|Destination|HikeDraft|CityVisitDraft> */
final class ContentEditVoter extends Voter
{
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::DELETE) {
            return $subject instanceof Place
                || $subject instanceof HikeDraft
                || $subject instanceof CityVisitDraft;
        }

        return $attribute === self::EDIT
            && (
                $subject instanceof Article
                || $subject instanceof Place
                || $subject instanceof Destination
                || $subject instanceof HikeDraft
                || $subject instanceof CityVisitDraft
            );
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User
            && $user->isAdmin()
            && $user->isVerified();
    }
}
