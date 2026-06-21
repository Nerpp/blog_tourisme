<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Repository\CommentRepository;
use DateTimeImmutable;

final class CommentRepositoryTest extends IntegrationTestCase
{
    public function testAnonymousReaderOnlyGetsApprovedReplies(): void
    {
        $author = $this->createUser();
        $article = $this->article($author);
        $root = $this->comment($author, $article, CommentStatus::Approved, 'Racine publique pour les réponses.');
        $approved = $this->reply($author, $article, $root, CommentStatus::Approved, 'Réponse publique approuvée.');
        $this->reply($author, $article, $root, CommentStatus::Pending, 'Réponse encore en attente.');
        $this->reply($author, $article, $root, CommentStatus::Rejected, 'Réponse rejetée et privée.');
        $this->reply($author, $article, $root, CommentStatus::HiddenPendingReport, 'Réponse masquée après signalement.');
        $this->reply($author, $article, $root, CommentStatus::HiddenByAdmin, 'Réponse masquée par administration.');
        $this->reply($author, $article, $root, CommentStatus::Deleted, 'Réponse supprimée administrativement.');
        $this->flushAndClear();

        $article = $this->reloadArticle($article);
        $results = $this->repository()->findApprovedForArticle($article);

        self::assertCount(1, $results);
        self::assertSame($root->getId(), $results[0]->getId());
        self::assertSame(
            [$approved->getId()],
            array_map(static fn (Comment $comment): ?int => $comment->getId(), $results[0]->getChildren()->toArray()),
        );
    }

    public function testAuthenticatedAuthorOnlyGetsApprovedRootsAndRepliesIncludingTheirOwn(): void
    {
        $viewer = $this->createUser();
        $otherAuthor = $this->createUser();
        $article = $this->article($viewer);
        $root = $this->comment($viewer, $article, CommentStatus::Approved, 'Racine personnelle publique.');
        $this->comment($viewer, $article, CommentStatus::Pending, 'Racine personnelle en attente.');
        $this->comment($viewer, $article, CommentStatus::Rejected, 'Racine personnelle rejetée.');
        $this->comment($viewer, $article, CommentStatus::Spam, 'Racine personnelle classée comme spam.');
        $this->comment($viewer, $article, CommentStatus::HiddenPendingReport, 'Racine personnelle signalée et masquée.');
        $this->comment($viewer, $article, CommentStatus::HiddenByAdmin, 'Racine personnelle masquée par administration.');
        $this->comment($viewer, $article, CommentStatus::Deleted, 'Racine personnelle supprimée.');
        $approved = $this->reply($otherAuthor, $article, $root, CommentStatus::Approved, 'Réponse publique d’un autre auteur.');
        $this->reply($viewer, $article, $root, CommentStatus::Pending, 'Réponse personnelle en attente.');
        $this->reply($viewer, $article, $root, CommentStatus::Rejected, 'Réponse personnelle rejetée.');
        $this->reply($viewer, $article, $root, CommentStatus::Spam, 'Réponse personnelle classée comme spam.');
        $this->reply($otherAuthor, $article, $root, CommentStatus::Pending, 'Réponse privée d’un autre auteur.');
        $this->reply($viewer, $article, $root, CommentStatus::HiddenPendingReport, 'Réponse personnelle signalée et masquée.');
        $this->reply($viewer, $article, $root, CommentStatus::HiddenByAdmin, 'Réponse personnelle masquée par administration.');
        $this->reply($viewer, $article, $root, CommentStatus::Deleted, 'Réponse personnelle supprimée.');
        $this->entityManager->flush();
        $articleId = $article->getId();
        $viewerId = $viewer->getId();
        $this->entityManager->clear();

        $article = $this->findArticle($articleId);
        $viewer = $this->entityManager->find(User::class, $viewerId);
        self::assertInstanceOf(User::class, $viewer);
        $viewerResults = $this->repository()->findApprovedForArticle($article, $viewer);
        self::assertCount(1, $viewerResults);
        self::assertSame($root->getId(), $viewerResults[0]->getId());
        self::assertSame(
            [$approved->getId()],
            array_map(static fn (Comment $comment): ?int => $comment->getId(), $viewerResults[0]->getChildren()->toArray()),
        );
    }

    private function article(User $author): Article
    {
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle($this->uniqueToken('comment-repository-article'))
            ->setSlug($this->uniqueToken('comment-repository-slug'))
            ->setContent('Contenu publié utilisé pour tester les commentaires.')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
        $this->entityManager->persist($author);
        $this->entityManager->persist($article);

        return $article;
    }

    private function comment(
        User $author,
        Article $article,
        CommentStatus $status,
        string $content,
        ?Comment $parent = null,
    ): Comment {
        if (!$this->entityManager->contains($author)) {
            $this->entityManager->persist($author);
        }

        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setParent($parent)
            ->setContent($content.' '.$this->uniqueToken('comment'))
            ->setStatus($status);

        if ($status === CommentStatus::Approved) {
            $now = new DateTimeImmutable();
            $comment
                ->setApprovedAt($now)
                ->setPublishedAt($now);
        }

        $this->entityManager->persist($comment);

        return $comment;
    }

    private function reply(User $author, Article $article, Comment $parent, CommentStatus $status, string $content): Comment
    {
        return $this->comment($author, $article, $status, $content, $parent);
    }

    private function repository(): CommentRepository
    {
        $repository = $this->entityManager->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);

        return $repository;
    }

    private function reloadArticle(Article $article): Article
    {
        return $this->findArticle($article->getId());
    }

    private function findArticle(?int $articleId): Article
    {
        self::assertNotNull($articleId);
        $article = $this->entityManager->find(Article::class, $articleId);
        self::assertInstanceOf(Article::class, $article);

        return $article;
    }

    private function flushAndClear(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
