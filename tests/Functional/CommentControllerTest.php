<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\User;
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
        $content = 'Un commentaire fonctionnel valide et suffisamment long.';
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => $content,
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

        $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($content, (string) $client->getResponse()->getContent());
    }

    public function testVerifiedUserCanPostValidPlaceComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $place = $this->createPublishedPlace();
        $content = 'Un commentaire fonctionnel valide sur un lieu publié.';
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/places/%s', $place->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/places/%s/comments', $place->getSlug()), [
            'comment' => [
                'content' => $content,
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith(
            sprintf('/places/%s#comment-', $place->getSlug()),
            $client->getResponse()->headers->get('Location') ?? '',
        );

        $comment = $this->entityManager()->getRepository(Comment::class)->findOneBy([
            'place' => $place,
            'author' => $author,
        ]);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame(CommentStatus::Approved, $comment->getStatus());

        $client->request('GET', sprintf('/places/%s', $place->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($content, (string) $client->getResponse()->getContent());
    }

    public function testUnverifiedUserCannotPostArticleComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser(verified: false);
        $article = $this->createArticle();
        $client->loginUser($author);
        $repository = $this->entityManager()->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);
        $before = $repository->count(['article' => $article]);

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => 'Commentaire refusé car email non confirmé.',
            ],
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comments', $article->getSlug()));
        self::assertSame($before, $repository->count(['article' => $article]));
    }

    public function testArticleCommentWithoutSubmittedFormIsRejected(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $client->loginUser($author);
        $repository = $this->entityManager()->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);
        $before = $repository->count(['article' => $article]);

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'content' => 'Payload sans nom de formulaire commentaire.',
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-form', $article->getSlug()));
        self::assertSame($before, $repository->count(['article' => $article]));
    }

    public function testCommentingUnknownArticleReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('POST', '/articles/article-inexistant/comments', [
            'comment' => [
                'content' => 'Commentaire sur article inexistant.',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCommentingUnknownPlaceReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('POST', '/places/lieu-inexistant/comments', [
            'comment' => [
                'content' => 'Commentaire sur lieu inexistant.',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifiedUserCanPostReplyPublishedByDefault(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $replyAuthor = $this->createUser();
        $article = $this->createArticle();
        $parent = $this->createComment($author, $article);
        $client->loginUser($replyAuthor);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $replyContent = 'Une réponse publiée immédiatement et suffisamment longue.';

        $client->request('POST', sprintf('/comments/%d/reply', $parent->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/reply', $parent->getId())),
            'content' => $replyContent,
            'website' => '',
        ]);

        self::assertResponseRedirects();
        $reply = $this->entityManager()->getRepository(Comment::class)->findOneBy([
            'parent' => $parent,
            'author' => $replyAuthor,
        ]);
        self::assertInstanceOf(Comment::class, $reply);
        self::assertSame(CommentStatus::Approved, $reply->getStatus());

        $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($replyContent, (string) $client->getResponse()->getContent());
    }

    public function testEmptyReplyIsRejected(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $replyAuthor = $this->createUser();
        $article = $this->createArticle();
        $parent = $this->createComment($author, $article);
        $client->loginUser($replyAuthor);
        $repository = $this->entityManager()->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);
        $before = $repository->count(['parent' => $parent]);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/reply', $parent->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/reply', $parent->getId())),
            'content' => '',
            'website' => '',
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $parent->getId()));
        self::assertSame($before, $repository->count(['parent' => $parent]));
    }

    public function testReplyHoneypotIsRejected(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $replyAuthor = $this->createUser();
        $article = $this->createArticle();
        $parent = $this->createComment($author, $article);
        $client->loginUser($replyAuthor);
        $repository = $this->entityManager()->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);
        $before = $repository->count(['parent' => $parent]);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/reply', $parent->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/reply', $parent->getId())),
            'content' => 'Réponse piégée avec honeypot mais contenu assez long.',
            'website' => 'https://spam.example',
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $parent->getId()));
        self::assertSame($before, $repository->count(['parent' => $parent]));
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

    public function testHoneypotFilledArticleCommentIsRejected(): void
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
                'content' => 'Commentaire avec honeypot rempli côté robot.',
                'website' => 'https://spam.example',
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-form', $article->getSlug()));
        self::assertSame($before, $repository->count(['article' => $article]));
    }

    public function testCommentWithTooManyLinksIsRejected(): void
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
                'content' => 'Voici trop de liens https://a.example https://b.example https://c.example dans ce commentaire.',
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-form', $article->getSlug()));
        self::assertSame($before, $repository->count(['article' => $article]));
    }

    public function testRecentDuplicateCommentIsRejected(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $client->loginUser($author);
        $repository = $this->entityManager()->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);
        $content = 'Commentaire doublon exact suffisamment long.';

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => $content,
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => $content,
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-form', $article->getSlug()));
        self::assertSame(1, $repository->count(['article' => $article, 'author' => $author]));
    }

    public function testCommentContentIsEscapedOnPublicPage(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/articles/%s/comments', $article->getSlug()), [
            'comment' => [
                'content' => '<script>alert(1)</script> commentaire suffisamment long.',
                '_token' => $this->inputValue($crawler, 'input[name="comment[_token]"]'),
            ],
        ]);
        self::assertResponseRedirects();

        $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testAuthorCanEditApprovedComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $updatedContent = 'Commentaire modifié proprement avec assez de contenu.';
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/edit', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/edit', $comment->getId())),
            'content' => $updatedContent,
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));
        $comment = $this->refresh($comment);
        self::assertSame($updatedContent, $comment->getContent());
        self::assertSame(CommentStatus::Approved, $comment->getStatus());
        self::assertNotNull($comment->getEditedAt());
    }

    public function testInvalidEditKeepsPreviousContent(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $previousContent = $comment->getContent();
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/edit', $comment->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/edit', $comment->getId())),
            'content' => 'court',
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $comment->getId()));
        $comment = $this->refresh($comment);
        self::assertSame($previousContent, $comment->getContent());
        self::assertNull($comment->getEditedAt());
    }

    public function testOtherUserCannotEditComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $otherUser = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $previousContent = $comment->getContent();
        $client->loginUser($author);
        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $token = $this->tokenFromFormAction($crawler, sprintf('/comments/%d/edit', $comment->getId()));

        $client->loginUser($otherUser);

        $client->request('POST', sprintf('/comments/%d/edit', $comment->getId()), [
            '_token' => $token,
            'content' => 'Tentative de modification par un autre utilisateur.',
        ]);

        self::assertResponseStatusCodeSame(403);
        $comment = $this->refresh($comment);
        self::assertSame($previousContent, $comment->getContent());
    }

    public function testHiddenCommentCannotBeReactivatedByAuthorEdit(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article, CommentStatus::HiddenPendingReport);
        $client->loginUser($author);

        $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            sprintf('/comments/%d/edit', $comment->getId()),
            (string) $client->getResponse()->getContent(),
        );
        $comment = $this->refresh($comment);
        self::assertSame(CommentStatus::HiddenPendingReport, $comment->getStatus());
    }

    public function testAuthorCanDeleteComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $commentId = $comment->getId();
        self::assertNotNull($commentId);
        $client->loginUser($author);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/delete', $commentId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/delete', $commentId)),
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comments', $article->getSlug()));
        self::assertNull($this->entityManager()->getRepository(Comment::class)->find($commentId));
    }

    public function testAuthorCanDeleteReplyAndReturnToParent(): void
    {
        $client = static::createClient();
        $parentAuthor = $this->createUser();
        $replyAuthor = $this->createUser();
        $article = $this->createArticle();
        $parent = $this->createComment($parentAuthor, $article);
        $reply = $this->createReplyComment($replyAuthor, $parent);
        $replyId = $reply->getId();
        self::assertNotNull($replyId);
        $client->loginUser($replyAuthor);

        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/comments/%d/delete', $replyId), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/comments/%d/delete', $replyId)),
        ]);

        self::assertResponseRedirects(sprintf('/articles/%s#comment-%d', $article->getSlug(), $parent->getId()));
        self::assertNull($this->entityManager()->getRepository(Comment::class)->find($replyId));
    }

    public function testOtherUserCannotDeleteComment(): void
    {
        $client = static::createClient();
        $author = $this->createUser();
        $otherUser = $this->createUser();
        $article = $this->createArticle();
        $comment = $this->createComment($author, $article);
        $commentId = $comment->getId();
        self::assertNotNull($commentId);
        $client->loginUser($author);
        $crawler = $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        $token = $this->tokenFromFormAction($crawler, sprintf('/comments/%d/delete', $commentId));

        $client->loginUser($otherUser);

        $client->request('POST', sprintf('/comments/%d/delete', $commentId), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Comment::class, $this->entityManager()->getRepository(Comment::class)->find($commentId));
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
        self::assertResponseRedirects(sprintf('/articles/%s#comments', $article->getSlug()));

        $client->request('POST', sprintf('/comments/%d/report', $comment->getId()), $payload);
        self::assertResponseRedirects(sprintf('/articles/%s#comments', $article->getSlug()));

        $reportRepository = $this->entityManager()->getRepository(\App\Entity\CommentReport::class);
        self::assertInstanceOf(CommentReportRepository::class, $reportRepository);
        self::assertSame(1, $reportRepository->count(['comment' => $comment, 'reporter' => $reporter]));
        $comment = $this->refresh($comment);
        self::assertSame(1, $comment->getReportedCount());
        self::assertSame(CommentStatus::HiddenPendingReport, $comment->getStatus());

        $client->request('GET', sprintf('/articles/%s', $article->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Commentaire fonctionnel assez long.', (string) $client->getResponse()->getContent());
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

    private function createReplyComment(User $author, Comment $parent, CommentStatus $status = CommentStatus::Approved): Comment
    {
        $now = new \DateTimeImmutable('-1 hour');
        $reply = (new Comment())
            ->setAuthor($author)
            ->setParent($parent)
            ->setContent('Réponse fonctionnelle assez longue.')
            ->setStatus($status);
        $parent->getChildren()->add($reply);

        if ($parent->getArticle() !== null) {
            $reply->setArticle($parent->getArticle());
        }

        if ($parent->getPlace() !== null) {
            $reply->setPlace($parent->getPlace());
        }

        if ($status === CommentStatus::Approved) {
            $reply
                ->setPublishedAt($now)
                ->setApprovedAt($now);
        }

        $this->persistAndFlush($reply);

        return $reply;
    }
}
