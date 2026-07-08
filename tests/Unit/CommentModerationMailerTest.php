<?php

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\User;
use App\Service\CommentModerationMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

final class CommentModerationMailerTest extends TestCase
{
    public function testSendCommentRejectedBuildsTemplatedEmailForValidAuthor(): void
    {
        $author = (new User())
            ->setEmail('reader@example.test')
            ->setDisplayName('Reader')
            ->setRejectedCommentsCount(2);
        $comment = (new Comment())
            ->setAuthor($author)
            ->setContent('Commentaire refuse pour test.');

        $transport = $this->createMock(MailerInterface::class);
        $transport
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email) use ($author, $comment): bool {
                return ($email->getFrom()[0]->getAddress() ?? null) === 'moderation@example.test'
                    && ($email->getTo()[0]->getAddress() ?? null) === 'reader@example.test'
                    && $email->getSubject() === 'Votre commentaire n’a pas été accepté sur Estela Explorations'
                    && $email->getHtmlTemplate() === 'emails/comment_rejected.html.twig'
                    && ($email->getContext()['author'] ?? null) === $author
                    && ($email->getContext()['comment'] ?? null) === $comment
                    && ($email->getContext()['reason'] ?? null) === 'Hors charte.'
                    && ($email->getContext()['rejected_count'] ?? null) === 2
                    && ($email->getContext()['auto_banned'] ?? null) === true;
            }));

        (new CommentModerationMailer($transport, 'moderation@example.test'))
            ->sendCommentRejected($comment, 'Hors charte.', true);
    }

    public function testSendCommentRejectedSkipsCommentWithoutValidAuthorEmail(): void
    {
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::never())->method('send');
        $mailer = new CommentModerationMailer($transport, 'moderation@example.test');

        $mailer->sendCommentRejected(new Comment(), 'Sans auteur.', false);
        $mailer->sendCommentRejected(
            (new Comment())->setAuthor(new User()),
            'Email absent.',
            false,
        );
        $mailer->sendCommentRejected(
            (new Comment())->setAuthor((new User())->setEmail('adresse-invalide')),
            'Email invalide.',
            false,
        );

        self::addToAssertionCount(3);
    }
}
