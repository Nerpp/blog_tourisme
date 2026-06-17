<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\ModerationActionLog;
use App\Entity\PublicationNotificationLog;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Entity\UserModerationWarning;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SmallEntityBehaviorTest extends TestCase
{
    public function testCommentLikeStoresCommentUserAndTimestamps(): void
    {
        $comment = new Comment();
        $user = (new User())->setEmail('reader@example.test')->setPassword('x');
        $like = (new CommentLike())
            ->setComment($comment)
            ->setUser($user);

        self::assertNull($like->getId());
        self::assertSame($comment, $like->getComment());
        self::assertSame($user, $like->getUser());

        $like->initializeTimestamps();
        self::assertInstanceOf(DateTimeImmutable::class, $like->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $like->getUpdatedAt());
    }

    public function testResetPasswordRequestStoresTokenDataAndRequiresUser(): void
    {
        $user = (new User())->setEmail('user@example.test')->setPassword('x');
        $expiresAt = new DateTimeImmutable('+1 hour');
        $request = new ResetPasswordRequest($user, $expiresAt, 'selector-token', 'hashed-token');

        self::assertNull($request->getId());
        self::assertSame($user, $request->getUser());
        self::assertSame($expiresAt, $request->getExpiresAt());
        self::assertSame('hashed-token', $request->getHashedToken());
        self::assertFalse($request->isExpired());

        $userProperty = new \ReflectionProperty($request, 'user');
        $userProperty->setValue($request, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('aucun utilisateur');
        $request->getUser();
    }

    public function testPublicationNotificationLogStoresPayloadAndClampsRecipientCount(): void
    {
        $log = new PublicationNotificationLog('article', 42, -5);

        self::assertNull($log->getId());
        self::assertSame('article', $log->getContentType());
        self::assertSame(42, $log->getContentId());
        self::assertSame(0, $log->getRecipientCount());
        self::assertInstanceOf(DateTimeImmutable::class, $log->getSentAt());
        self::assertInstanceOf(DateTimeImmutable::class, $log->getCreatedAt());
    }

    public function testUserModerationWarningStoresRelationsAndTrimsReason(): void
    {
        $user = (new User())->setEmail('warned@example.test')->setPassword('x');
        $admin = (new User())->setEmail('admin@example.test')->setPassword('x');
        $comment = new Comment();
        $longReason = '  '.str_repeat('é', 300).'  ';

        $warning = (new UserModerationWarning())
            ->setUser($user)
            ->setComment($comment)
            ->setCreatedBy($admin)
            ->setReason($longReason);

        self::assertNull($warning->getId());
        self::assertSame($user, $warning->getUser());
        self::assertSame($comment, $warning->getComment());
        self::assertSame($admin, $warning->getCreatedBy());
        self::assertSame(255, mb_strlen((string) $warning->getReason()));

        $warning->setComment(null)->setCreatedBy(null)->setReason(null);
        self::assertNull($warning->getComment());
        self::assertNull($warning->getCreatedBy());
        self::assertNull($warning->getReason());
    }

    public function testModerationActionLogNormalizesScalarFields(): void
    {
        $actor = (new User())->setEmail('actor@example.test')->setPassword('x');
        $targetUser = (new User())->setEmail('target@example.test')->setPassword('x');
        $metadata = ['reason' => 'spam'];

        $log = (new ModerationActionLog())
            ->setActor($actor)
            ->setTargetUser($targetUser)
            ->setAction('  '.str_repeat('a', 90))
            ->setTargetType('  '.str_repeat('comment', 20))
            ->setTargetId(99)
            ->setSummary('  Résumé  ')
            ->setMetadata($metadata)
            ->setIpAddress(str_repeat('1', 60))
            ->setUserAgent(str_repeat('u', 600));

        self::assertSame($actor, $log->getActor());
        self::assertSame($targetUser, $log->getTargetUser());
        self::assertSame(80, mb_strlen($log->getAction()));
        self::assertSame(80, mb_strlen($log->getTargetType()));
        self::assertSame(99, $log->getTargetId());
        self::assertSame('Résumé', $log->getSummary());
        self::assertSame($metadata, $log->getMetadata());
        self::assertSame(45, mb_strlen((string) $log->getIpAddress()));
        self::assertSame(500, mb_strlen((string) $log->getUserAgent()));

        $log->setSummary('   ')->setMetadata(null)->setIpAddress(null)->setUserAgent(null);
        self::assertNull($log->getSummary());
        self::assertNull($log->getMetadata());
        self::assertNull($log->getIpAddress());
        self::assertNull($log->getUserAgent());
    }
}
