<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\CommentReportReason;
use App\Enum\CommentReportStatus;
use App\Repository\CommentReportRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentReportRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_comment_report_reporter', fields: ['comment', 'reporter'])]
#[ORM\Index(name: 'idx_comment_report_status', fields: ['status'])]
#[ORM\Index(name: 'idx_comment_report_comment', fields: ['comment'])]
#[ORM\Index(name: 'idx_comment_report_reporter', fields: ['reporter'])]
#[ORM\HasLifecycleCallbacks]
class CommentReport
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Comment::class, inversedBy: 'reports')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Comment $comment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $reporter = null;

    #[ORM\Column(length: 30, enumType: CommentReportReason::class)]
    private CommentReportReason $reason = CommentReportReason::Other;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 20, enumType: CommentReportStatus::class)]
    private CommentReportStatus $status = CommentReportStatus::Pending;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return sprintf('Signalement #%d', $this->id ?? 0);
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

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(?User $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function getReason(): CommentReportReason
    {
        return $this->reason;
    }

    public function setReason(CommentReportReason $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message === null ? null : trim($message);

        return $this;
    }

    public function getStatus(): CommentReportStatus
    {
        return $this->status;
    }

    public function setStatus(CommentReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 500);

        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }
}
