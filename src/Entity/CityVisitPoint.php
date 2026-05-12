<?php

namespace App\Entity;

use App\Enum\CityVisitPointType;
use App\Repository\CityVisitPointRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityVisitPointRepository::class)]
#[ORM\Index(name: 'idx_city_visit_point_type', fields: ['type'])]
#[ORM\Index(name: 'idx_city_visit_point_position', fields: ['position'])]
#[ORM\HasLifecycleCallbacks]
class CityVisitPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CityVisitDraft::class, inversedBy: 'points')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CityVisitDraft $cityVisitDraft = null;

    #[ORM\Column(length: 20, enumType: CityVisitPointType::class)]
    private CityVisitPointType $type = CityVisitPointType::Other;

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

    /** @var Collection<int, CityVisitPointMedia> */
    #[ORM\OneToMany(mappedBy: 'cityVisitPoint', targetEntity: CityVisitPointMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getCityVisitDraft(): ?CityVisitDraft
    {
        return $this->cityVisitDraft;
    }

    public function setCityVisitDraft(?CityVisitDraft $cityVisitDraft): static
    {
        $this->cityVisitDraft = $cityVisitDraft;

        return $this;
    }

    public function getType(): CityVisitPointType
    {
        return $this->type;
    }

    public function setType(CityVisitPointType $type): static
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

    /** @return Collection<int, CityVisitPointMedia> */
    public function getMediaLinks(): Collection
    {
        return $this->mediaLinks;
    }

    public function addMediaLink(CityVisitPointMedia $mediaLink): static
    {
        if (!$this->mediaLinks->contains($mediaLink)) {
            $this->mediaLinks->add($mediaLink);
            $mediaLink->setCityVisitPoint($this);
        }

        return $this;
    }

    public function removeMediaLink(CityVisitPointMedia $mediaLink): static
    {
        if ($this->mediaLinks->removeElement($mediaLink) && $mediaLink->getCityVisitPoint() === $this) {
            $mediaLink->setCityVisitPoint(null);
        }

        return $this;
    }
}
