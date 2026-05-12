<?php

namespace App\Entity;

use App\Enum\HikePointType;
use App\Repository\HikePointRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HikePointRepository::class)]
#[ORM\Index(name: 'idx_hike_point_type', fields: ['type'])]
#[ORM\Index(name: 'idx_hike_point_position', fields: ['position'])]
#[ORM\HasLifecycleCallbacks]
class HikePoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HikeDraft::class, inversedBy: 'points')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?HikeDraft $hikeDraft = null;

    #[ORM\Column(length: 20, enumType: HikePointType::class)]
    private HikePointType $type = HikePointType::Other;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private ?float $latitude = null;

    #[ORM\Column]
    private ?float $longitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $accuracy = null;

    #[ORM\Column]
    private int $position = 1;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedCommuneName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $detectedCommuneCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedDepartmentName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detectedRegionName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    /** @var Collection<int, HikePointMedia> */
    #[ORM\OneToMany(mappedBy: 'hikePoint', targetEntity: HikePointMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $mediaLinks;

    public function __construct()
    {
        $this->mediaLinks = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title ?? sprintf('Point #%d', $this->position);
    }

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt ??= new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHikeDraft(): ?HikeDraft
    {
        return $this->hikeDraft;
    }

    public function setHikeDraft(?HikeDraft $hikeDraft): static
    {
        $this->hikeDraft = $hikeDraft;

        return $this;
    }

    public function getType(): HikePointType
    {
        return $this->type;
    }

    public function setType(HikePointType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getAccuracy(): ?float
    {
        return $this->accuracy;
    }

    public function setAccuracy(?float $accuracy): static
    {
        $this->accuracy = $accuracy;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /** @return Collection<int, HikePointMedia> */
    public function getMediaLinks(): Collection
    {
        return $this->mediaLinks;
    }

    public function addMediaLink(HikePointMedia $mediaLink): static
    {
        if (!$this->mediaLinks->contains($mediaLink)) {
            $this->mediaLinks->add($mediaLink);
            $mediaLink->setHikePoint($this);
        }

        return $this;
    }

    public function removeMediaLink(HikePointMedia $mediaLink): static
    {
        if ($this->mediaLinks->removeElement($mediaLink) && $mediaLink->getHikePoint() === $this) {
            $mediaLink->setHikePoint(null);
        }

        return $this;
    }
}
