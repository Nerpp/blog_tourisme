<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

final class CommentModerationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from,
    ) {
    }

    public function sendCommentRejected(Comment $comment, string $reason, bool $autoBanned): void
    {
        $author = $comment->getAuthor();
        if (!$author instanceof User || !filter_var($author->getEmail(), FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to($author->getEmail())
            ->subject('Votre commentaire n’a pas été accepté sur Estela Explorations')
            ->htmlTemplate('emails/comment_rejected.html.twig')
            ->context([
                'author' => $author,
                'comment' => $comment,
                'reason' => $reason,
                'rejected_count' => $author->getRejectedCommentsCount(),
                'auto_banned' => $autoBanned,
            ]);

        $this->mailer->send($email);
    }
}
