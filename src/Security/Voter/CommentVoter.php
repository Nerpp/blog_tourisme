<?php

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CommentVoter extends Voter
{
    public const EDIT = 'COMMENT_EDIT';
    public const DELETE = 'COMMENT_DELETE';
    public const REPORT = 'COMMENT_REPORT';
    public const MODERATE = 'COMMENT_MODERATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::EDIT, self::DELETE, self::REPORT, self::MODERATE], true)) {
            return false;
        }

        return $subject instanceof Comment || $attribute === self::MODERATE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($attribute === self::MODERATE) {
            return $this->isAdmin($user);
        }

        if ($user->isBanned() && !$this->isAdmin($user)) {
            return false;
        }

        if (!$subject instanceof Comment) {
            return false;
        }

        return match ($attribute) {
            self::EDIT => $subject->getStatus() !== CommentStatus::Deleted
                && ($this->isOwner($subject, $user) || $this->isAdmin($user)),
            self::DELETE => $subject->getStatus() !== CommentStatus::Deleted
                && ($this->isOwner($subject, $user) || $this->isAdmin($user)),
            self::REPORT => $subject->getStatus() === CommentStatus::Approved
                && !$this->isOwner($subject, $user),
            default => false,
        };
    }

    private function isOwner(Comment $comment, User $user): bool
    {
        return $comment->getAuthor()?->getId() !== null
            && $comment->getAuthor()?->getId() === $user->getId();
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
