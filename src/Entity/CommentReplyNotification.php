<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\CommentReplyNotificationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentReplyNotificationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_comment_reply_notification_recipient_comment', fields: ['recipient', 'comment'])]
#[ORM\Index(name: 'idx_comment_reply_notification_recipient_read', fields: ['recipient', 'readAt'])]
#[ORM\Index(name: 'idx_comment_reply_notification_comment', fields: ['comment'])]
#[ORM\HasLifecycleCallbacks]
class CommentReplyNotification
{
    use TimestampableTrait;

    public const KIND_REPLY = 'reply';
    public const KIND_MENTION = 'mention';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    #[ORM\ManyToOne(targetEntity: Comment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Comment $comment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $triggeredBy = null;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_REPLY;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(User $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function setComment(Comment $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(?User $triggeredBy): static
    {
        $this->triggeredBy = $triggeredBy;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = in_array($kind, [self::KIND_REPLY, self::KIND_MENTION], true)
            ? $kind
            : self::KIND_REPLY;

        return $this;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): static
    {
        $this->readAt ??= new DateTimeImmutable();

        return $this;
    }
}
