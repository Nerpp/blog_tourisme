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
#[ORM\Index(name: 'idx_comment_article', fields: ['article'])]
#[ORM\Index(name: 'idx_comment_place', fields: ['place'])]
#[ORM\Index(name: 'idx_comment_parent', fields: ['parent'])]
#[ORM\Index(name: 'idx_comment_reported_count', fields: ['reportedCount'])]
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

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: Place::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Place $place = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 5000)]
    private ?string $content = null;

    #[ORM\Column(length: 20, enumType: CommentStatus::class)]
    private CommentStatus $status = CommentStatus::Pending;

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

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->reports = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): static
    {
        $this->article = $article;

        if ($article !== null) {
            $this->place = null;
        }

        return $this;
    }

    public function getPlace(): ?Place
    {
        return $this->place;
    }

    public function setPlace(?Place $place): static
    {
        $this->place = $place;

        if ($place !== null) {
            $this->article = null;
        }

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

    public function markDeleted(): static
    {
        $this->status = CommentStatus::Deleted;
        $this->content = 'Commentaire supprime par son auteur.';

        return $this;
    }

    #[Assert\Callback]
    public function validateTarget(ExecutionContextInterface $context): void
    {
        $hasArticle = $this->article !== null;
        $hasPlace = $this->place !== null;

        if ($hasArticle === $hasPlace) {
            $context
                ->buildViolation('Un commentaire doit etre lie soit a un article soit a un lieu.')
                ->atPath('article')
                ->addViolation();
        }
    }
}
