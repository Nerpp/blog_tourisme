<?php

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentLikeRepository;
use App\Service\CommentReactionViewService;
use PHPUnit\Framework\TestCase;

final class CommentReactionViewServiceTest extends TestCase
{
    public function testBuildContextCollectsParentAndChildIdsForAnonymousViewer(): void
    {
        $parent = $this->commentWithId(10);
        $child = $this->commentWithId(11);
        $duplicate = $this->commentWithId(10);
        $parent->getChildren()->add($child);
        $parent->getChildren()->add(new Comment());

        $repository = $this->createMock(CommentLikeRepository::class);
        $repository
            ->expects(self::once())
            ->method('countByCommentIds')
            ->with([10, 11])
            ->willReturn([10 => 3, 11 => 1]);
        $repository
            ->expects(self::never())
            ->method('findLikedCommentIdsForUser');

        $context = (new CommentReactionViewService($repository))->buildContext([$parent, $duplicate, new Comment()], null);

        self::assertSame(2, $context['comment_count']);
        self::assertSame([10 => 3, 11 => 1], $context['like_counts']);
        self::assertSame([], $context['liked_comment_ids']);
    }

    public function testBuildContextIncludesViewerLikes(): void
    {
        $viewer = (new User())->setEmail('viewer@example.test')->setPassword('x');
        $comment = $this->commentWithId(42);

        $repository = $this->createMock(CommentLikeRepository::class);
        $repository
            ->expects(self::once())
            ->method('countByCommentIds')
            ->with([42])
            ->willReturn([42 => 2]);
        $repository
            ->expects(self::once())
            ->method('findLikedCommentIdsForUser')
            ->with($viewer, [42])
            ->willReturn([42]);

        $context = (new CommentReactionViewService($repository))->buildContext([$comment], $viewer);

        self::assertSame(1, $context['comment_count']);
        self::assertSame([42 => 2], $context['like_counts']);
        self::assertSame([42], $context['liked_comment_ids']);
    }

    private function commentWithId(int $id): Comment
    {
        $comment = new Comment();
        $property = new \ReflectionProperty($comment, 'id');
        $property->setValue($comment, $id);

        return $comment;
    }
}
