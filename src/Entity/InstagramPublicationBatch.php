<?php

namespace App\Entity;

use App\Entity\Traits\InstagramErrorTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\InstagramPublicationBatchStatus;
use App\Repository\InstagramPublicationBatchRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstagramPublicationBatchRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instagram_publication_batch_position', fields: ['publication', 'position'])]
#[ORM\Index(name: 'idx_instagram_publication_batch_status', fields: ['status'])]
#[ORM\Index(name: 'idx_instagram_publication_batch_publication_status', fields: ['publication', 'status'])]
#[ORM\HasLifecycleCallbacks]
class InstagramPublicationBatch
{
    use InstagramErrorTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InstagramPublication::class, inversedBy: 'batches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?InstagramPublication $publication = null;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 20, enumType: InstagramPublicationBatchStatus::class)]
    private InstagramPublicationBatchStatus $status = InstagramPublicationBatchStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column]
    private int $mediaCount = 0;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $firstAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $containerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instagramMediaId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastErrorCode = null;

    /** @var Collection<int, InstagramPublicationMedia> */
    #[ORM\OneToMany(mappedBy: 'batch', targetEntity: InstagramPublicationMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['batchPosition' => 'ASC', 'id' => 'ASC'])]
    private Collection $media;

    public function __construct(
        InstagramPublication $publication,
        int $position,
        ?string $caption = null,
    ) {
        $this->publication = $publication;
        $this->position = $position;
        $this->caption = self::normalizeInstagramText($caption);
        $this->media = new ArrayCollection();
        $publication->addBatch($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): ?InstagramPublication
    {
        return $this->publication;
    }

    public function setPublication(?InstagramPublication $publication): static
    {
        $this->publication = $publication;

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

    public function getStatus(): InstagramPublicationBatchStatus
    {
        return $this->status;
    }

    public function setStatus(InstagramPublicationBatchStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): static
    {
        $this->caption = self::normalizeInstagramText($caption);

        return $this;
    }

    public function getMediaCount(): int
    {
        return $this->mediaCount;
    }

    public function setMediaCount(int $mediaCount): static
    {
        $this->mediaCount = max(0, $mediaCount);

        return $this;
    }

    public function synchronizeMediaCount(): static
    {
        $this->mediaCount = $this->media->count();

        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $attemptCount): static
    {
        $this->attemptCount = max(0, $attemptCount);

        return $this;
    }

    public function registerAttempt(?DateTimeImmutable $attemptedAt = null): static
    {
        $attemptedAt ??= new DateTimeImmutable();
        $this->attemptCount++;
        $this->firstAttemptAt ??= $attemptedAt;
        $this->lastAttemptAt = $attemptedAt;

        return $this;
    }

    public function getFirstAttemptAt(): ?DateTimeImmutable
    {
        return $this->firstAttemptAt;
    }

    public function setFirstAttemptAt(?DateTimeImmutable $firstAttemptAt): static
    {
        $this->firstAttemptAt = $firstAttemptAt;

        return $this;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(?DateTimeImmutable $lastAttemptAt): static
    {
        $this->lastAttemptAt = $lastAttemptAt;

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

    public function getContainerId(): ?string
    {
        return $this->containerId;
    }

    public function setContainerId(?string $containerId): static
    {
        $this->containerId = self::normalizeInstagramText($containerId, 255);

        return $this;
    }

    public function getInstagramMediaId(): ?string
    {
        return $this->instagramMediaId;
    }

    public function setInstagramMediaId(?string $instagramMediaId): static
    {
        $this->instagramMediaId = self::normalizeInstagramText($instagramMediaId, 255);

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = self::sanitizeInstagramError($lastError);

        return $this;
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function setLastErrorCode(?string $lastErrorCode): static
    {
        $this->lastErrorCode = self::normalizeInstagramText($lastErrorCode, 100);

        return $this;
    }

    public function clearLastError(): static
    {
        $this->lastError = null;
        $this->lastErrorCode = null;

        return $this;
    }

    /** @return Collection<int, InstagramPublicationMedia> */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function addMedia(InstagramPublicationMedia $media): static
    {
        if (!$this->media->contains($media)) {
            $this->media->add($media);
        }

        if ($media->getBatch() !== $this) {
            $media->setBatch($this);
        }

        return $this;
    }

    public function removeMedia(InstagramPublicationMedia $media): static
    {
        if ($this->media->removeElement($media) && $media->getBatch() === $this) {
            $media->setBatch(null);
        }

        return $this;
    }
}
