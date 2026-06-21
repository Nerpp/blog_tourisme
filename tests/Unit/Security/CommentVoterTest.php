<?php

namespace App\Tests\Unit\Security;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Security\Voter\CommentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CommentVoterTest extends TestCase
{
    public function testOnlyOwnerCanDeleteCommentThatIsNotAlreadyDeleted(): void
    {
        $owner = $this->user(10);
        $other = $this->user(20);
        $comment = (new Comment())
            ->setAuthor($owner)
            ->setStatus(CommentStatus::Approved);
        $voter = new CommentVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($owner), $comment, [CommentVoter::DELETE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($other), $comment, [CommentVoter::DELETE]));

        $comment->setStatus(CommentStatus::Deleted);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($owner), $comment, [CommentVoter::DELETE]));
    }

    public function testReportRequiresVerifiedNonOwnerAndApprovedComment(): void
    {
        $owner = $this->user(10);
        $reporter = $this->user(20);
        $unverifiedReporter = $this->user(30, verified: false);
        $comment = (new Comment())
            ->setAuthor($owner)
            ->setStatus(CommentStatus::Approved);
        $voter = new CommentVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($reporter), $comment, [CommentVoter::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($owner), $comment, [CommentVoter::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($unverifiedReporter), $comment, [CommentVoter::REPORT]));

        $comment->setStatus(CommentStatus::Rejected);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($reporter), $comment, [CommentVoter::REPORT]));
    }

    public function testAnonymousInvalidSubjectAndUnknownActionAreNeverGranted(): void
    {
        $owner = $this->user(10);
        $comment = (new Comment())
            ->setAuthor($owner)
            ->setStatus(CommentStatus::Approved);
        $anonymousToken = $this->createStub(TokenInterface::class);
        $anonymousToken->method('getUser')->willReturn(null);
        $voter = new CommentVoter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($anonymousToken, $comment, [CommentVoter::REPORT]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token($owner), null, [CommentVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token($owner), $comment, ['COMMENT_UNKNOWN']));
    }

    private function user(int $id, bool $verified = true): User
    {
        $user = (new User())
            ->setEmail(sprintf('user-%d@example.test', $id))
            ->setPassword('password')
            ->setIsVerified($verified);

        $property = new \ReflectionProperty($user, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
