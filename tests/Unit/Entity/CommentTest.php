<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Place;
use App\Entity\User;
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

    public function testAdministrativeReactionsCanBeAppliedAndRemoved(): void
    {
        $admin = (new User())->setEmail('admin@example.test')->setPassword('x');
        $comment = new Comment();

        $comment->toggleAdminHeart($admin)->togglePinned($admin);
        self::assertTrue($comment->hasAdminHeart());
        self::assertSame($admin, $comment->getAdminHeartedBy());
        self::assertTrue($comment->isPinned());
        self::assertSame($admin, $comment->getPinnedBy());

        $comment->toggleAdminHeart($admin)->togglePinned($admin);
        self::assertFalse($comment->hasAdminHeart());
        self::assertNull($comment->getAdminHeartedBy());
        self::assertFalse($comment->isPinned());
        self::assertNull($comment->getPinnedBy());
    }

    public function testStringRepresentationFallsBackForEmptyContent(): void
    {
        $comment = new Comment();

        self::assertSame('Commentaire #0', (string) $comment);

        $comment->setContent(str_repeat('a', 100));
        self::assertSame(str_repeat('a', 80), (string) $comment);
    }
}
