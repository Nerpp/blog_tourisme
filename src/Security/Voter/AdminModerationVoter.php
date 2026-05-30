<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class AdminModerationVoter extends Voter
{
    public const COMMENT_MODERATE = 'COMMENT_MODERATE';
    public const COMMENT_APPROVE = 'COMMENT_APPROVE';
    public const COMMENT_REJECT = 'COMMENT_REJECT';
    public const COMMENT_DELETE = 'COMMENT_DELETE';
    public const COMMENT_SPAM = 'COMMENT_SPAM';
    public const COMMENT_RESTORE = 'COMMENT_RESTORE';
    public const COMMENT_REPORT_REVIEW = 'COMMENT_REPORT_REVIEW';

    private const ATTRIBUTES = [
        self::COMMENT_MODERATE,
        self::COMMENT_APPROVE,
        self::COMMENT_REJECT,
        self::COMMENT_DELETE,
        self::COMMENT_SPAM,
        self::COMMENT_RESTORE,
        self::COMMENT_REPORT_REVIEW,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User
            && in_array('ROLE_ADMIN', $user->getRoles(), true)
            && $user->isVerified();
    }
}
