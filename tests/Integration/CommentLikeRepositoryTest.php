<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Repository\CommentLikeRepository;

final class CommentLikeRepositoryTest extends IntegrationTestCase
{
    public function testCountAndViewerLikeProjectionsReturnIntegerIdentifiers(): void
    {
        $author = $this->createUser();
        $viewer = $this->createUser();
        $otherUser = $this->createUser();
        $this->entityManager->persist($viewer);
        $this->entityManager->persist($otherUser);
        $article = $this->article($author);
        $firstComment = $this->comment($author, $article, 'Premier commentaire pour les likes.');
        $secondComment = $this->comment($author, $article, 'Second commentaire pour les likes.');
        $thirdComment = $this->comment($author, $article, 'Commentaire sans like.');

        $this->entityManager->persist((new CommentLike())->setComment($firstComment)->setUser($viewer));
        $this->entityManager->persist((new CommentLike())->setComment($firstComment)->setUser($otherUser));
        $this->entityManager->persist((new CommentLike())->setComment($secondComment)->setUser($otherUser));
        $this->entityManager->flush();

        $commentIds = [
            $this->id($firstComment),
            $this->id($secondComment),
            $this->id($thirdComment),
        ];
        $repository = $this->repository();

        self::assertSame([
            $commentIds[0] => 2,
            $commentIds[1] => 1,
        ], $repository->countByCommentIds($commentIds));
        self::assertSame([$commentIds[0]], $repository->findLikedCommentIdsForUser($viewer, $commentIds));
    }

    private function article(User $author): Article
    {
        $token = $this->uniqueToken('comment-like-repository');
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle('Article likes '.$token)
            ->setSlug('article-likes-'.$token)
            ->setContent('Contenu publié pour les projections de likes.')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));

        $this->entityManager->persist($author);
        $this->entityManager->persist($article);

        return $article;
    }

    private function comment(User $author, Article $article, string $content): Comment
    {
        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent($content)
            ->setStatus(CommentStatus::Approved)
            ->setPublishedAt(new \DateTimeImmutable('-1 hour'))
            ->setApprovedAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($comment);

        return $comment;
    }

    private function id(Comment $comment): int
    {
        $id = $comment->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function repository(): CommentLikeRepository
    {
        $repository = $this->entityManager->getRepository(CommentLike::class);
        self::assertInstanceOf(CommentLikeRepository::class, $repository);

        return $repository;
    }
}
