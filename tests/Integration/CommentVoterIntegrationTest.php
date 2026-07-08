<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Security\Voter\CommentVoter;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CommentVoterIntegrationTest extends IntegrationTestCase
{
    public function testOwnerCanEditOwnApprovedComment(): void
    {
        $owner = $this->persistUser();
        $comment = $this->persistComment($owner, CommentStatus::Approved);
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($owner), $comment, [CommentVoter::EDIT]));
    }

    public function testOtherUserCannotEditComment(): void
    {
        $owner = $this->persistUser();
        $other = $this->persistUser();
        $comment = $this->persistComment($owner, CommentStatus::Approved);
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($other), $comment, [CommentVoter::EDIT]));
    }

    public function testVerifiedAdminCanModerateAndReportButCannotEditSomeoneElsesComment(): void
    {
        $owner = $this->persistUser();
        $admin = $this->persistUser(['ROLE_ADMIN', 'ROLE_USER']);
        $comment = $this->persistComment($owner, CommentStatus::Approved);
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), null, [CommentVoter::MODERATE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), $comment, [CommentVoter::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($admin), $comment, [CommentVoter::EDIT]));
    }

    public function testUnverifiedAdminCannotModerate(): void
    {
        $admin = $this->persistUser(['ROLE_ADMIN', 'ROLE_USER'], false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token($admin), null, [CommentVoter::MODERATE]));
    }

    public function testBannedOwnerCannotEditComment(): void
    {
        $owner = $this->persistUser();
        $owner
            ->setIsBanned(true)
            ->setBannedAt(new \DateTimeImmutable());
        $comment = $this->persistComment($owner, CommentStatus::Approved);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token($owner), $comment, [CommentVoter::EDIT]));
    }

    public function testOnlyApprovedCommentIsEditableByOwner(): void
    {
        $owner = $this->persistUser();
        $voter = $this->voter();

        foreach (CommentStatus::cases() as $status) {
            $expected = $status === CommentStatus::Approved
                ? VoterInterface::ACCESS_GRANTED
                : VoterInterface::ACCESS_DENIED;

            self::assertSame($expected, $voter->vote($this->token($owner), $this->persistComment($owner, $status), [CommentVoter::EDIT]));
        }
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(array $roles = ['ROLE_USER'], bool $verified = true): User
    {
        $user = $this->createUser($roles, $verified);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function persistComment(User $author, CommentStatus $status): Comment
    {
        $token = $this->uniqueToken('comment-voter');
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle('Article voter '.$token)
            ->setSlug('article-voter-'.$token)
            ->setExcerpt('Extrait voter.')
            ->setContent('<p>Contenu voter.</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));

        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent('Commentaire voter suffisamment long.')
            ->setStatus($status);

        if ($status === CommentStatus::Approved) {
            $comment
                ->setPublishedAt(new \DateTimeImmutable('-1 hour'))
                ->setApprovedAt(new \DateTimeImmutable('-1 hour'));
        }

        $this->entityManager->persist($article);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $comment;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function voter(): CommentVoter
    {
        $voter = $this->service(CommentVoter::class);
        self::assertInstanceOf(CommentVoter::class, $voter);

        return $voter;
    }
}
