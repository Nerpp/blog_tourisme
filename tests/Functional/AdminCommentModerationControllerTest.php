<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Enum\CommentStatus;

final class AdminCommentModerationControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromModerationList(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/comments');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromModerationList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/comments');

        self::assertResponseRedirects('/');
    }

    public function testUnverifiedAdminIsRejectedFromModerationList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER'], false));

        $client->request('GET', '/admin/comments');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenModerationList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(['ROLE_ADMIN', 'ROLE_USER']));

        $client->request('GET', '/admin/comments');

        self::assertResponseIsSuccessful();
    }

    public function testVerifiedAdminCanApprovePendingComment(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $comment = $this->createComment($this->createUser(), $this->createArticle(), CommentStatus::Pending);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/comments/pending');
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/comments/%d/approve', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/comments/%d/approve', $comment->getId())),
            'returnUrl' => '/admin/comments/pending',
        ]);

        self::assertResponseRedirects('/admin/comments/pending');
        $comment = $this->refresh($comment);
        self::assertSame(CommentStatus::Approved, $comment->getStatus());
    }

    public function testVerifiedAdminCanRejectPendingCommentWithReason(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $comment = $this->createComment($this->createUser(), $this->createArticle(), CommentStatus::Pending);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/comments/pending');
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/comments/%d/reject', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/comments/%d/reject', $comment->getId())),
            'returnUrl' => '/admin/comments/pending',
            'reason' => 'Hors charte de test.',
        ]);

        self::assertResponseRedirects('/admin/comments/pending');
        $comment = $this->refresh($comment);
        self::assertSame(CommentStatus::Rejected, $comment->getStatus());
        self::assertSame('Hors charte de test.', $comment->getModerationReason());
    }

    public function testVerifiedAdminCanPinApprovedCommentFromModeration(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $comment = $this->createComment($this->createUser(), $this->createArticle());
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/comments?filter=approved');
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/comments/%d/pin', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/comments/%d/pin', $comment->getId())),
            'returnUrl' => '/admin/comments?filter=approved',
        ]);

        self::assertResponseRedirects('/admin/comments?filter=approved');
        $comment = $this->refresh($comment);
        self::assertTrue($comment->isPinned());
    }

    public function testVerifiedAdminCanDeleteCommentFromModeration(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $comment = $this->createComment($this->createUser(), $this->createArticle());
        $commentId = $comment->getId();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/comments?filter=all');
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/comments/%d/delete', $commentId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/comments/%d/delete', $commentId)),
            'returnUrl' => '/admin/comments?filter=all',
        ]);

        self::assertResponseRedirects('/admin/comments?filter=all');
        self::assertNull($this->entityManager()->find(Comment::class, $commentId));
    }
}
