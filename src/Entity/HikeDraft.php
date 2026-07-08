<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\HikeDraftStatus;
use App\Repository\HikeDraftRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HikeDraftRepository::class)]
#[ORM\Index(name: 'idx_hike_draft_status', fields: ['status'])]
#[ORM\HasLifecycleCallbacks]
class HikeDraft
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $title = null;

    #[ORM\Column(length: 190, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 20, enumType: HikeDraftStatus::class)]
    private HikeDraftStatus $status = HikeDraftStatus::Draft;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: Destination::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Destination $destination = null;

    #[ORM\ManyToOne(targetEntity: Destination::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Destination $geographicDestination = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedCommuneName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $detectedCommuneCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedDepartmentName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedRegionName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, HikePoint> */
    #[ORM\OneToMany(mappedBy: 'hikeDraft', targetEntity: HikePoint::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $points;

    /** @var Collection<int, HikeDraftMedia> */
    #[ORM\OneToMany(mappedBy: 'hikeDraft', targetEntity: HikeDraftMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $mediaLinks;

    /** @var Collection<int, ArticleHike> */
    #[ORM\OneToMany(mappedBy: 'hikeDraft', targetEntity: ArticleHike::class)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $articleLinks;

    public function __construct()
    {
        $this->points = new ArrayCollection();
        $this->mediaLinks = new ArrayCollection();
        $this->articleLinks = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title ?? sprintf('Randonnée rapide #%d', $this->id ?? 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getStatus(): HikeDraftStatus
    {
        return $this->status;
    }

    public function setStatus(HikeDraftStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getDestination(): ?Destination
    {
        return $this->destination;
    }

    public function setDestination(?Destination $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function getGeographicDestination(): ?Destination
    {
        return $this->geographicDestination;
    }

    public function setGeographicDestination(?Destination $geographicDestination): static
    {
        $this->geographicDestination = $geographicDestination;

        return $this;
    }

    public function getDetectedCommuneName(): ?string
    {
        return $this->detectedCommuneName;
    }

    public function setDetectedCommuneName(?string $detectedCommuneName): static
    {
        $this->detectedCommuneName = $detectedCommuneName;

        return $this;
    }

    public function getDetectedCommuneCode(): ?string
    {
        return $this->detectedCommuneCode;
    }

    public function setDetectedCommuneCode(?string $detectedCommuneCode): static
    {
        $this->detectedCommuneCode = $detectedCommuneCode;

        return $this;
    }

    public function getDetectedDepartmentName(): ?string
    {
        return $this->detectedDepartmentName;
    }

    public function setDetectedDepartmentName(?string $detectedDepartmentName): static
    {
        $this->detectedDepartmentName = $detectedDepartmentName;

        return $this;
    }

    public function getDetectedRegionName(): ?string
    {
        return $this->detectedRegionName;
    }

    public function setDetectedRegionName(?string $detectedRegionName): static
    {
        $this->detectedRegionName = $detectedRegionName;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, HikePoint> */
    public function getPoints(): Collection
    {
        return $this->points;
    }

    public function addPoint(HikePoint $point): static
    {
        if (!$this->points->contains($point)) {
            $this->points->add($point);
            $point->setHikeDraft($this);
        }

        return $this;
    }

    public function removePoint(HikePoint $point): static
    {
        if ($this->points->removeElement($point) && $point->getHikeDraft() === $this) {
            $point->setHikeDraft(null);
        }

        return $this;
    }

    /** @return Collection<int, HikeDraftMedia> */
    public function getMediaLinks(): Collection
    {
        return $this->mediaLinks;
    }

    /** @return Collection<int, ArticleHike> */
    public function getArticleLinks(): Collection
    {
        return $this->articleLinks;
    }

    public function addMediaLink(HikeDraftMedia $mediaLink): static
    {
        if (!$this->mediaLinks->contains($mediaLink)) {
            $this->mediaLinks->add($mediaLink);
            $mediaLink->setHikeDraft($this);
        }

        return $this;
    }

    public function removeMediaLink(HikeDraftMedia $mediaLink): static
    {
        $this->mediaLinks->removeElement($mediaLink);

        return $this;
    }
}
