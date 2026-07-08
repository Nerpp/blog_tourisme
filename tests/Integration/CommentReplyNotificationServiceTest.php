<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\CommentReplyNotification;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Repository\CommentReplyNotificationRepository;
use App\Service\CommentReplyNotificationService;

final class CommentReplyNotificationServiceTest extends IntegrationTestCase
{
    public function testCreatesReplyNotificationForApprovedReplyToAnotherUser(): void
    {
        [$article, $parentAuthor, $replyAuthor] = $this->articleWithUsers();
        $parent = $this->comment($article, $parentAuthor);
        $reply = $this->comment($article, $replyAuthor, $parent, 'Une reponse approuvee assez longue.');

        $this->notificationService()->createForApprovedComment($reply);
        $this->entityManager->flush();

        $notification = $this->notificationRepository()->findOneByRecipientAndComment($parentAuthor, $reply);
        self::assertInstanceOf(CommentReplyNotification::class, $notification);
        self::assertSame($replyAuthor->getId(), $notification->getTriggeredBy()?->getId());
        self::assertSame(CommentReplyNotification::KIND_REPLY, $notification->getKind());
    }

    public function testDoesNotNotifySelfPendingCommentsOrDuplicateNotifications(): void
    {
        [$article, $author] = $this->articleWithUsers(1);
        $parent = $this->comment($article, $author);
        $selfReply = $this->comment($article, $author, $parent, 'Je me reponds a moi meme.');

        $this->notificationService()->createForApprovedComment($selfReply);
        $this->entityManager->flush();

        self::assertNull($this->notificationRepository()->findOneByRecipientAndComment($author, $selfReply));

        $pendingReply = $this->comment($article, $author, $parent, 'Reponse en attente.', CommentStatus::Pending);
        $this->notificationService()->createForApprovedComment($pendingReply);
        $this->entityManager->flush();

        self::assertNull($this->notificationRepository()->findOneByRecipientAndComment($author, $pendingReply));
    }

    public function testCreatesMentionNotificationAndDoesNotDuplicateParentRecipient(): void
    {
        [$article, $parentAuthor, $replyAuthor, $mentionedUser] = $this->articleWithUsers(3);
        $parent = $this->comment($article, $parentAuthor);
        $reply = $this->comment(
            $article,
            $replyAuthor,
            $parent,
            sprintf('Merci @%s et @%s pour vos idees.', $parentAuthor->getMentionHandle(), $mentionedUser->getMentionHandle()),
        );

        $this->notificationService()->createForApprovedComment($reply);
        $this->entityManager->flush();
        $this->notificationService()->createForApprovedComment($reply);
        $this->entityManager->flush();

        self::assertCount(2, $this->notificationRepository()->findBy(['comment' => $reply]));
        self::assertSame(
            1,
            $this->notificationRepository()->count(['recipient' => $parentAuthor, 'comment' => $reply]),
        );
        self::assertSame(
            CommentReplyNotification::KIND_MENTION,
            $this->notificationRepository()->findOneByRecipientAndComment($mentionedUser, $reply)?->getKind(),
        );
    }

    public function testRepositoryCountsFindsAndDeletesOnlyRecipientNotifications(): void
    {
        [$article, $recipient, $otherRecipient, $triggeredBy] = $this->articleWithUsers(3);
        $readApprovedComment = $this->comment($article, $triggeredBy, content: 'Commentaire approuve lu.');
        $unreadApprovedComment = $this->comment($article, $triggeredBy, content: 'Commentaire approuve non lu.');
        $rejectedComment = $this->comment($article, $triggeredBy, status: CommentStatus::Rejected);
        $readNotification = (new CommentReplyNotification())
            ->setRecipient($recipient)
            ->setComment($readApprovedComment)
            ->setTriggeredBy($triggeredBy)
            ->markRead();
        $unreadNotification = (new CommentReplyNotification())
            ->setRecipient($recipient)
            ->setComment($unreadApprovedComment)
            ->setTriggeredBy($triggeredBy);
        $rejectedNotification = (new CommentReplyNotification())
            ->setRecipient($recipient)
            ->setComment($rejectedComment)
            ->setTriggeredBy($triggeredBy);
        $otherNotification = (new CommentReplyNotification())
            ->setRecipient($otherRecipient)
            ->setComment($unreadApprovedComment)
            ->setTriggeredBy($triggeredBy);

        $this->entityManager->persist($readNotification);
        $this->entityManager->persist($unreadNotification);
        $this->entityManager->persist($rejectedNotification);
        $this->entityManager->persist($otherNotification);
        $this->entityManager->flush();

        self::assertSame(1, $this->notificationRepository()->countUnreadForRecipient($recipient));
        self::assertCount(2, $this->notificationRepository()->findRecentForRecipient($recipient));

        self::assertSame(3, $this->notificationRepository()->deleteAllForRecipient($recipient));
        $this->entityManager->clear();

        self::assertSame(1, $this->notificationRepository()->count(['recipient' => $otherRecipient]));
    }

    /**
     * @return list<User|Article>
     */
    private function articleWithUsers(int $extraUsers = 2): array
    {
        $article = (new Article())
            ->setTitle('Article notifications '.$this->uniqueToken('article'))
            ->setSlug($this->uniqueToken('article'))
            ->setContent('<p>Contenu de test</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($article);

        $users = [];
        for ($i = 0; $i <= $extraUsers; ++$i) {
            $user = $this->createUser();
            $user->setDisplayName(sprintf('Notif User %d %s', $i, $this->uniqueToken('mention')));
            $this->entityManager->persist($user);
            $users[] = $user;
        }

        $article->setAuthor($users[0]);
        $this->entityManager->flush();

        return [$article, ...$users];
    }

    private function comment(
        Article $article,
        User $author,
        ?Comment $parent = null,
        string $content = 'Commentaire approuve assez long.',
        CommentStatus $status = CommentStatus::Approved,
    ): Comment {
        $comment = (new Comment())
            ->setArticle($article)
            ->setAuthor($author)
            ->setParent($parent)
            ->setContent($content)
            ->setStatus($status);

        if ($status === CommentStatus::Approved) {
            $comment
                ->setApprovedAt(new \DateTimeImmutable('-1 hour'))
                ->setPublishedAt(new \DateTimeImmutable('-1 hour'));
        }

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $comment;
    }

    private function notificationService(): CommentReplyNotificationService
    {
        $service = $this->service(CommentReplyNotificationService::class);
        self::assertInstanceOf(CommentReplyNotificationService::class, $service);

        return $service;
    }

    private function notificationRepository(): CommentReplyNotificationRepository
    {
        $repository = $this->entityManager->getRepository(CommentReplyNotification::class);
        self::assertInstanceOf(CommentReplyNotificationRepository::class, $repository);

        return $repository;
    }
}
