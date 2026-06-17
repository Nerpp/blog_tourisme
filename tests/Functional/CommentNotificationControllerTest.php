<?php

namespace App\Tests\Functional;

use App\Entity\CommentReplyNotification;
use App\Enum\CommentStatus;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CommentNotificationControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromNotifications(): void
    {
        $client = static::createClient();

        $client->request('GET', '/notifications/commentaires');

        self::assertResponseRedirects('/login');
    }

    public function testLoggedInUserCanOpenEmptyNotificationsPage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/notifications/commentaires');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Notifications');
    }

    public function testExistingNotificationIsDisplayed(): void
    {
        $client = static::createClient();
        $recipient = $this->createUser();
        $author = $this->createUser();
        $comment = $this->createComment($author, $this->createArticle());
        $this->createCommentReplyNotification($recipient, $comment, $author);
        $client->loginUser($recipient);

        $client->request('GET', '/notifications/commentaires');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $author->getDisplayName());
    }

    public function testClearNotificationsRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $recipient = $this->createUser();
        $notification = $this->createCommentReplyNotification($recipient, $this->createComment($this->createUser(), $this->createArticle()));
        $client->loginUser($recipient);
        $client->catchExceptions(false);

        $this->expectException(AccessDeniedException::class);

        $client->request('POST', '/notifications/commentaires/vider', ['_token' => 'bad-token']);
    }

    public function testClearNotificationsDeletesOnlyCurrentUserNotifications(): void
    {
        $client = static::createClient();
        $recipient = $this->createUser();
        $otherRecipient = $this->createUser();
        $currentUserNotification = $this->createCommentReplyNotification($recipient, $this->createComment($this->createUser(), $this->createArticle()));
        $otherNotification = $this->createCommentReplyNotification($otherRecipient, $this->createComment($this->createUser(), $this->createArticle()));
        $client->loginUser($recipient);
        $crawler = $client->request('GET', '/notifications/commentaires');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/notifications/commentaires/vider', [
            '_token' => $this->tokenFromFormAction($crawler, '/notifications/commentaires/vider'),
        ]);

        self::assertResponseRedirects('/notifications/commentaires');
        self::assertNull($this->entityManager()->find(CommentReplyNotification::class, $currentUserNotification->getId()));
        self::assertNotNull($this->entityManager()->find(CommentReplyNotification::class, $otherNotification->getId()));
    }

    public function testOpenNotificationMarksItReadAndRedirectsToComment(): void
    {
        $client = static::createClient();
        $recipient = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($this->createUser(), $article);
        $notification = $this->createCommentReplyNotification($recipient, $comment);
        $client->loginUser($recipient);

        $client->request('GET', sprintf('/notifications/commentaires/%d', $notification->getId()));

        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));
        $notification = $this->refresh($notification);
        self::assertTrue($notification->isRead());
    }

    public function testOpeningAnotherUsersNotificationIsDenied(): void
    {
        $client = static::createClient();
        $recipient = $this->createUser();
        $notification = $this->createCommentReplyNotification($recipient, $this->createComment($this->createUser(), $this->createArticle()));
        $client->loginUser($this->createUser());
        $client->catchExceptions(false);

        $this->expectException(AccessDeniedException::class);

        $client->request('GET', sprintf('/notifications/commentaires/%d', $notification->getId()));
    }

    public function testOpeningNotificationForUnavailableCommentDeletesItAndRedirectsToNotifications(): void
    {
        $client = static::createClient();
        $recipient = $this->createUser();
        $comment = $this->createComment($this->createUser(), $this->createArticle(), CommentStatus::Rejected);
        $notification = $this->createCommentReplyNotification($recipient, $comment);
        $notificationId = $notification->getId();
        self::assertNotNull($notificationId);
        $client->loginUser($recipient);

        $client->request('GET', sprintf('/notifications/commentaires/%d', $notificationId));

        self::assertResponseRedirects('/notifications/commentaires');
        self::assertNull($this->entityManager()->find(CommentReplyNotification::class, $notificationId));
    }
}
