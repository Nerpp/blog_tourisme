<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Place;
use App\Enum\CommentStatus;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    public function testChangingTargetKeepsArticleAndPlaceMutuallyExclusive(): void
    {
        $article = new Article();
        $place = new Place();
        $comment = new Comment();

        $comment->setArticle($article);
        self::assertSame($article, $comment->getArticle());
        self::assertNull($comment->getPlace());

        $comment->setPlace($place);
        self::assertSame($place, $comment->getPlace());
        self::assertNull($comment->getArticle());

        $comment->setArticle($article);
        self::assertSame($article, $comment->getArticle());
        self::assertNull($comment->getPlace());
    }

    public function testCommentCannotBecomeItsOwnParent(): void
    {
        $parent = new Comment();
        $comment = (new Comment())->setParent($parent);

        self::assertSame($parent, $comment->getParent());

        $comment->setParent($comment);

        self::assertSame($parent, $comment->getParent());
    }

    public function testMarkDeletedReplacesContentAndSetsDeletedStatus(): void
    {
        $comment = (new Comment())
            ->setContent('Contenu public qui ne doit plus rester visible.')
            ->setStatus(CommentStatus::Approved);

        $comment->markDeleted();

        self::assertSame(CommentStatus::Deleted, $comment->getStatus());
        self::assertSame('Commentaire supprime par son auteur.', $comment->getContent());
    }
}
