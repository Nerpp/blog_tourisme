<?php

namespace App\Entity;

use App\Repository\PublicationNotificationLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicationNotificationLogRepository::class)]
#[ORM\Table(name: 'publication_notification_log')]
#[ORM\UniqueConstraint(name: 'uniq_publication_notification_content', columns: ['content_type', 'content_id'])]
#[ORM\Index(name: 'idx_publication_notification_sent_at', fields: ['sentAt'])]
class PublicationNotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $contentType;

    #[ORM\Column]
    private int $contentId;

    #[ORM\Column]
    private DateTimeImmutable $sentAt;

    #[ORM\Column]
    private int $recipientCount;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(string $contentType, int $contentId, int $recipientCount)
    {
        $now = new DateTimeImmutable();
        $this->contentType = $contentType;
        $this->contentId = $contentId;
        $this->recipientCount = max(0, $recipientCount);
        $this->sentAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
