<?php

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentReplyNotificationRepository;
use App\Repository\CommentReportRepository;
use App\Service\CommentDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CommentDeletionServiceTest extends TestCase
{
    public function testDeletePhysicallyRemovesDescendantsBeforeParentAndCleansDependencies(): void
    {
        $parent = $this->comment('Parent');
        $child = $this->comment('Child');
        $grandChild = $this->comment('Grand child');
        $sibling = $this->comment('Sibling');
        $parent->getChildren()->add($child->setParent($parent));
        $parent->getChildren()->add($sibling->setParent($parent));
        $child->getChildren()->add($grandChild->setParent($child));
        $expectedOrder = [$grandChild, $child, $sibling, $parent];

        $likes = $this->createMock(CommentLikeRepository::class);
        $likes
            ->expects(self::once())
            ->method('deleteForComments')
            ->with($expectedOrder);
        $reports = $this->createMock(CommentReportRepository::class);
        $reports
            ->expects(self::once())
            ->method('deleteForComments')
            ->with($expectedOrder);
        $notifications = $this->createMock(CommentReplyNotificationRepository::class);
        $notifications
            ->expects(self::once())
            ->method('deleteForComments')
            ->with($expectedOrder);

        $removed = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(4))
            ->method('remove')
            ->willReturnCallback(static function (object $entity) use (&$removed): void {
                $removed[] = $entity;
            });

        (new CommentDeletionService($likes, $reports, $notifications, $entityManager))
            ->deletePhysically($parent);

        self::assertSame($expectedOrder, $removed);
    }

    private function comment(string $content): Comment
    {
        return (new Comment())->setContent($content);
    }
}
