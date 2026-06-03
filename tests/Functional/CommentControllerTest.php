<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Enum\CommentReportReason;
use App\Enum\CommentStatus;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentReportRepository;
use App\Repository\CommentRepository;

final class CommentControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorCannotPostArticleComment(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => ['content' => 'Commentaire anonyme assez long.'],
        ]);

        self::assertResponseRedirects('/login');
    }

    public function testVerifiedUserCanPostValidArticleComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => 'Un commentaire fonctionnel valide et suffisamment long.',
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith(
            sprintf('/articles/%s#comment-', $article->getSlug()),
            $client->getResponse()->headers->get('Location') ?? '',
        );

        $comment = $this->entityManager()->getRepository(Comment::class)->findOneBy([
            'article' => $article,
            'author' => $author,
        ]);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame(CommentStatus::Approved, $comment->getStatus());
    }

    public function testInvalidArticleCommentIsRejected(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $client->loginUser($author);
        $repository = $this->entityManager()->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);
        $before = $repository->count(['article' => $article]);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => 'court',
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-form', $article->getSlug()));
        self::assertSame($before, $repository->count(['article' => $article]));
    }

    public function testLikeToggleCreatesAndRemovesLike(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $liker = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $client->loginUser($liker);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $token = $this->tokenFromFormAction($crawler, sprintf('/comments/%d/like', $comment->getId()));

        $client->request('POST', sprintf('/comments/%d/like', $comment->getId()), ['_token' => $token]);
        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));

        $likeRepository = $this->entityManager()->getRepository(\App\Entity\CommentLike::class);
        self::assertInstanceOf(CommentLikeRepository::class, $likeRepository);
        self::assertSame(1, $likeRepository->count(['comment' => $comment, 'user' => $liker]));

        $client->request('POST', sprintf('/comments/%d/like', $comment->getId()), ['_token' => $token]);
        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));
        self::assertSame(0, $likeRepository->count(['comment' => $comment, 'user' => $liker]));
    }

    public function testVerifiedUserCanReportCommentOnlyOnce(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $reporter = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $client->loginUser($reporter);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $token = $this->tokenFromFormAction($crawler, sprintf('/comments/%d/report', $comment->getId()));

        $payload = [
            '_token' => $token,
            'reason' => CommentReportReason::Spam->value,
            'message' => 'Signalement de test.',
        ];
        $client->request('POST', sprintf('/comments/%d/report', $comment->getId()), $payload);
        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));

        $client->request('POST', sprintf('/comments/%d/report', $comment->getId()), $payload);
        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));

        $reportRepository = $this->entityManager()->getRepository(\App\Entity\CommentReport::class);
        self::assertInstanceOf(CommentReportRepository::class, $reportRepository);
        self::assertSame(1, $reportRepository->count(['comment' => $comment, 'reporter' => $reporter]));
        $comment = $this->refresh($comment);
        self::assertSame(1, $comment->getReportedCount());
    }

    public function testVerifiedAdminCanHeartAndPinApprovedComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $admin = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/admin-heart', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/admin-heart', $comment->getId())),
        ]);
        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));
        $comment = $this->refresh($comment);
        self::assertNotNull($comment->getAdminHeartedAt());

        $client->request('POST', sprintf('/comments/%d/pin', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/pin', $comment->getId())),
        ]);
        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));
        $comment = $this->refresh($comment);
        self::assertTrue($comment->isPinned());
    }
}
