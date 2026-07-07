<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\CommentReplyNotification;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class CommentInteractionFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    use TestFixtureGroup;

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);
        $user = $this->getUser(UserFixtures::USER_REFERENCE);
        $trusted = $this->getUser(UserFixtures::TRUSTED_REFERENCE);
        $unverified = $this->getUser(UserFixtures::UNVERIFIED_REFERENCE);
        $noAvatar = $this->getUser(UserFixtures::NO_AVATAR_REFERENCE);

        $likes = [
            [CommentFixtures::ARTICLE_POPULAR_REFERENCE, UserFixtures::ADMIN_REFERENCE],
            [CommentFixtures::ARTICLE_POPULAR_REFERENCE, UserFixtures::TRUSTED_REFERENCE],
            [CommentFixtures::ARTICLE_POPULAR_REFERENCE, UserFixtures::UNVERIFIED_REFERENCE],
            [CommentFixtures::ARTICLE_POPULAR_REFERENCE, UserFixtures::NO_AVATAR_REFERENCE],
            [CommentFixtures::ARTICLE_ADMIN_HEART_REFERENCE, UserFixtures::USER_REFERENCE],
            [CommentFixtures::PLACE_FORT_APPROVED_REFERENCE, UserFixtures::USER_REFERENCE],
        ];

        foreach ($likes as [$commentReference, $userReference]) {
            $manager->persist((new CommentLike())
                ->setComment($this->getComment($commentReference))
                ->setUser($this->getUser($userReference)));
        }

        $notifications = [
            [$user, CommentFixtures::ARTICLE_APPROVED_REPLY_REFERENCE, $admin, CommentReplyNotification::KIND_REPLY, null],
            [$user, CommentFixtures::ARTICLE_SECOND_REPLY_REFERENCE, $trusted, CommentReplyNotification::KIND_REPLY, null],
            [$user, CommentFixtures::ARTICLE_MENTION_REFERENCE, $user, CommentReplyNotification::KIND_MENTION, null],
            [$user, CommentFixtures::ARTICLE_PINNED_REFERENCE, $admin, CommentReplyNotification::KIND_REPLY, new DateTimeImmutable('-1 day 08:00')],
            [$user, CommentFixtures::ARTICLE_ADMIN_HEART_REFERENCE, $trusted, CommentReplyNotification::KIND_MENTION, new DateTimeImmutable('-2 days 08:00')],
            [$trusted, CommentFixtures::ARTICLE_MENTION_REFERENCE, $user, CommentReplyNotification::KIND_MENTION, null],
            [$noAvatar, CommentFixtures::PLACE_PAULILLES_APPROVED_REFERENCE, $user, CommentReplyNotification::KIND_REPLY, null],
            [$unverified, CommentFixtures::ARTICLE_LONG_REFERENCE, $noAvatar, CommentReplyNotification::KIND_REPLY, new DateTimeImmutable('-3 days 08:00')],
        ];

        foreach ($notifications as [$recipient, $commentReference, $triggeredBy, $kind, $readAt]) {
            $manager->persist((new CommentReplyNotification())
                ->setRecipient($recipient)
                ->setComment($this->getComment($commentReference))
                ->setTriggeredBy($triggeredBy)
                ->setKind($kind)
                ->setReadAt($readAt));
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            CommentFixtures::class,
        ];
    }

    private function getUser(string $reference): User
    {
        return $this->getReference($reference, User::class);
    }

    private function getComment(string $reference): Comment
    {
        return $this->getReference($reference, Comment::class);
    }
}
