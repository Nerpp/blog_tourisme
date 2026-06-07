<?php

namespace App\Entity;

use App\Repository\PrevisionDestinationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrevisionDestinationRepository::class)]
#[ORM\Index(name: 'idx_prevision_destination_status', fields: ['status'])]
#[ORM\Index(name: 'idx_prevision_destination_updated_at', fields: ['updatedAt'])]
#[ORM\HasLifecycleCallbacks]
class PrevisionDestination
{
    public const STATUS_IDEA = 'idea';
    public const STATUS_TO_CHECK = 'to_check';
    public const STATUS_TO_VISIT = 'to_visit';
    public const STATUS_SPOTTED = 'spotted';
    public const STATUS_ABANDONED = 'abandoned';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SEARCH = 'search';
    public const SOURCE_GPS = 'gps';
    public const SOURCE_MANUAL_MAP = 'manual_map';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $title = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_IDEA;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $source = self::SOURCE_MANUAL;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $commune = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $inseeCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $gpsAccuracy = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $priority = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $plannedPeriod = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $this->normalizeNullableString($notes);

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $this->normalizeNullableString($country);

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $this->normalizeNullableString($region);

        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = $this->normalizeNullableString($department);

        return $this;
    }

    public function getCommune(): ?string
    {
        return $this->commune;
    }

    public function setCommune(?string $commune): static
    {
        $this->commune = $this->normalizeNullableString($commune);

        return $this;
    }

    public function getInseeCode(): ?string
    {
        return $this->inseeCode;
    }

    public function setInseeCode(?string $inseeCode): static
    {
        $this->inseeCode = $this->normalizeNullableString($inseeCode);

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $this->normalizeNullableString($postalCode);

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getGpsAccuracy(): ?float
    {
        return $this->gpsAccuracy;
    }

    public function setGpsAccuracy(?float $gpsAccuracy): static
    {
        $this->gpsAccuracy = $gpsAccuracy;

        return $this;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(?string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPlannedPeriod(): ?string
    {
        return $this->plannedPeriod;
    }

    public function setPlannedPeriod(?string $plannedPeriod): static
    {
        $this->plannedPeriod = $this->normalizeNullableString($plannedPeriod);

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

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function initializeTimestamps(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
