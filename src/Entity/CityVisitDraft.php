<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\CityVisitDraftStatus;
use App\Repository\CityVisitDraftRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityVisitDraftRepository::class)]
#[ORM\Index(name: 'idx_city_visit_draft_status', fields: ['status'])]
#[ORM\HasLifecycleCallbacks]
class CityVisitDraft
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

    #[ORM\Column(length: 20, enumType: CityVisitDraftStatus::class)]
    private CityVisitDraftStatus $status = CityVisitDraftStatus::Draft;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedCommuneName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $detectedCommuneCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedDepartmentName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedRegionName = null;

    #[ORM\ManyToOne(targetEntity: Destination::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Destination $destination = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $googleMapsUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, CityVisitPoint> */
    #[ORM\OneToMany(mappedBy: 'cityVisitDraft', targetEntity: CityVisitPoint::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $points;

    /** @var Collection<int, CityVisitDraftMedia> */
    #[ORM\OneToMany(mappedBy: 'cityVisitDraft', targetEntity: CityVisitDraftMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $mediaLinks;

    /** @var Collection<int, ArticleCityVisit> */
    #[ORM\OneToMany(mappedBy: 'cityVisitDraft', targetEntity: ArticleCityVisit::class)]
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
        return $this->title ?? sprintf('Visite de ville #%d', $this->id ?? 0);
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

    public function getStatus(): CityVisitDraftStatus
    {
        return $this->status;
    }

    public function setStatus(CityVisitDraftStatus $status): static
    {
        $this->status = $status;

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

    public function getDestination(): ?Destination
    {
        return $this->destination;
    }

    public function setDestination(?Destination $destination): static
    {
        $this->destination = $destination;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getGoogleMapsUrl(): ?string
    {
        return $this->googleMapsUrl;
    }

    public function setGoogleMapsUrl(?string $googleMapsUrl): static
    {
        $this->googleMapsUrl = $googleMapsUrl;

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

    /** @return Collection<int, CityVisitPoint> */
    public function getPoints(): Collection
    {
        return $this->points;
    }

    public function addPoint(CityVisitPoint $point): static
    {
        if (!$this->points->contains($point)) {
            $this->points->add($point);
            $point->setCityVisitDraft($this);
        }

        return $this;
    }

    public function removePoint(CityVisitPoint $point): static
    {
        if ($this->points->removeElement($point) && $point->getCityVisitDraft() === $this) {
            $point->setCityVisitDraft(null);
        }

        return $this;
    }

    /** @return Collection<int, CityVisitDraftMedia> */
    public function getMediaLinks(): Collection
    {
        return $this->mediaLinks;
    }

    /** @return Collection<int, ArticleCityVisit> */
    public function getArticleLinks(): Collection
    {
        return $this->articleLinks;
    }

    public function addMediaLink(CityVisitDraftMedia $mediaLink): static
    {
        if (!$this->mediaLinks->contains($mediaLink)) {
            $this->mediaLinks->add($mediaLink);
            $mediaLink->setCityVisitDraft($this);
        }

        return $this;
    }

    public function removeMediaLink(CityVisitDraftMedia $mediaLink): static
    {
        $this->mediaLinks->removeElement($mediaLink);

        return $this;
    }
}
