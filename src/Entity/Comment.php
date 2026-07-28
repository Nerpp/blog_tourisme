<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\CommentStatus;
use App\Repository\CommentRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Index(name: 'idx_comment_status', fields: ['status'])]
#[ORM\Index(name: 'idx_comment_created_at', fields: ['createdAt'])]
#[ORM\Index(name: 'idx_comment_published_at', fields: ['publishedAt'])]
#[ORM\Index(name: 'idx_comment_approved_at', fields: ['approvedAt'])]
#[ORM\Index(name: 'idx_comment_author', fields: ['author'])]
#[ORM\Index(name: 'idx_comment_thread', fields: ['thread'])]
#[ORM\Index(name: 'idx_comment_parent', fields: ['parent'])]
#[ORM\Index(name: 'idx_comment_reported_count', fields: ['reportedCount'])]
#[ORM\Index(name: 'idx_comment_admin_hearted_by', fields: ['adminHeartedBy'])]
#[ORM\Index(name: 'idx_comment_pinned_at', fields: ['pinnedAt'])]
#[ORM\Index(name: 'idx_comment_pinned_by', fields: ['pinnedBy'])]
#[ORM\HasLifecycleCallbacks]
class Comment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: CommentThread::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CommentThread $thread = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'validation.comment.content.required')]
    #[Assert\Length(
        min: 10,
        max: 5000,
        minMessage: 'validation.comment.content.too_short',
        maxMessage: 'validation.comment.content.too_long',
    )]
    private ?string $content = null;

    #[ORM\Column(length: 20, enumType: CommentStatus::class)]
    private CommentStatus $status = CommentStatus::Approved;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $moderationReason = null;

    #[ORM\Column]
    private int $spamScore = 0;

    #[ORM\Column]
    private int $reportedCount = 0;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $moderatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'moderatedComments')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $moderatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    /** @var Collection<int, CommentReport> */
    #[ORM\OneToMany(mappedBy: 'comment', targetEntity: CommentReport::class)]
    private Collection $reports;

    /** @var Collection<int, CommentLike> */
    #[ORM\OneToMany(mappedBy: 'comment', targetEntity: CommentLike::class)]
    private Collection $likes;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $adminHeartedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $adminHeartedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $pinnedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $pinnedBy = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->likes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        $content = trim((string) $this->content);

        return $content === ''
            ? sprintf('Commentaire #%d', $this->id ?? 0)
            : mb_substr($content, 0, 80);
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getThread(): ?CommentThread
    {
        return $this->thread;
    }

    public function setThread(CommentThread $thread): static
    {
        if ($this->parent instanceof self && !$this->sameThread($thread, $this->parent->getThread())) {
            throw new \InvalidArgumentException('Une réponse doit appartenir au même fil que son commentaire parent.');
        }

        $this->thread = $thread;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getStatus(): CommentStatus
    {
        return $this->status;
    }

    public function setStatus(CommentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        if ($parent === $this) {
            return $this;
        }

        if ($parent instanceof self) {
            $parentThread = $parent->getThread();
            if ($this->thread instanceof CommentThread && !$this->sameThread($this->thread, $parentThread)) {
                throw new \InvalidArgumentException('Une réponse doit appartenir au même fil que son commentaire parent.');
            }

            if (!$this->thread instanceof CommentThread && $parentThread instanceof CommentThread) {
                $this->thread = $parentThread;
            }
        }

        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Comment> */
    public function getChildren(): Collection
    {
        return $this->children;
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

    public function getModerationReason(): ?string
    {
        return $this->moderationReason;
    }

    public function setModerationReason(?string $moderationReason): static
    {
        $this->moderationReason = $moderationReason === null ? null : mb_substr($moderationReason, 0, 255);

        return $this;
    }

    public function getSpamScore(): int
    {
        return $this->spamScore;
    }

    public function setSpamScore(int $spamScore): static
    {
        $this->spamScore = max(0, min(100, $spamScore));

        return $this;
    }

    public function getReportedCount(): int
    {
        return $this->reportedCount;
    }

    public function setReportedCount(int $reportedCount): static
    {
        $this->reportedCount = max(0, $reportedCount);

        return $this;
    }

    public function incrementReportedCount(): static
    {
        ++$this->reportedCount;

        return $this;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getModeratedAt(): ?DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function setModeratedAt(?DateTimeImmutable $moderatedAt): static
    {
        $this->moderatedAt = $moderatedAt;

        return $this;
    }

    public function getModeratedBy(): ?User
    {
        return $this->moderatedBy;
    }

    public function setModeratedBy(?User $moderatedBy): static
    {
        $this->moderatedBy = $moderatedBy;

        return $this;
    }

    public function getEditedAt(): ?DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function setEditedAt(?DateTimeImmutable $editedAt): static
    {
        $this->editedAt = $editedAt;

        return $this;
    }

    /** @return Collection<int, CommentReport> */
    public function getReports(): Collection
    {
        return $this->reports;
    }

    /** @return Collection<int, CommentLike> */
    public function getLikes(): Collection
    {
        return $this->likes;
    }

    public function getAdminHeartedAt(): ?DateTimeImmutable
    {
        return $this->adminHeartedAt;
    }

    public function setAdminHeartedAt(?DateTimeImmutable $adminHeartedAt): static
    {
        $this->adminHeartedAt = $adminHeartedAt;

        return $this;
    }

    public function getAdminHeartedBy(): ?User
    {
        return $this->adminHeartedBy;
    }

    public function setAdminHeartedBy(?User $adminHeartedBy): static
    {
        $this->adminHeartedBy = $adminHeartedBy;

        return $this;
    }

    public function hasAdminHeart(): bool
    {
        return $this->adminHeartedAt !== null;
    }

    public function toggleAdminHeart(User $admin): static
    {
        if ($this->hasAdminHeart()) {
            $this->adminHeartedAt = null;
            $this->adminHeartedBy = null;

            return $this;
        }

        $this->adminHeartedAt = new DateTimeImmutable();
        $this->adminHeartedBy = $admin;

        return $this;
    }

    public function getPinnedAt(): ?DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function setPinnedAt(?DateTimeImmutable $pinnedAt): static
    {
        $this->pinnedAt = $pinnedAt;

        return $this;
    }

    public function getPinnedBy(): ?User
    {
        return $this->pinnedBy;
    }

    public function setPinnedBy(?User $pinnedBy): static
    {
        $this->pinnedBy = $pinnedBy;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->pinnedAt !== null;
    }

    public function togglePinned(User $admin): static
    {
        if ($this->isPinned()) {
            $this->pinnedAt = null;
            $this->pinnedBy = null;

            return $this;
        }

        $this->pinnedAt = new DateTimeImmutable();
        $this->pinnedBy = $admin;

        return $this;
    }

    public function markDeleted(): static
    {
        $this->status = CommentStatus::Deleted;
        $this->content = 'Commentaire supprime par son auteur.';

        return $this;
    }

    #[Assert\Callback]
    public function validateTarget(ExecutionContextInterface $context): void
    {
        if (!$this->thread instanceof CommentThread) {
            $context
                ->buildViolation('validation.comment.target.invalid')
                ->atPath('thread')
                ->addViolation();

            return;
        }

        if ($this->parent instanceof self && !$this->sameThread($this->thread, $this->parent->getThread())) {
            $context
                ->buildViolation('validation.comment.target.invalid')
                ->atPath('parent')
                ->addViolation();
        }
    }

    private function sameThread(CommentThread $left, ?CommentThread $right): bool
    {
        if (!$right instanceof CommentThread) {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        return $left->getId() !== null && $left->getId() === $right->getId();
    }
}
