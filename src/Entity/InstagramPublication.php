<?php

namespace App\Entity;

use App\Entity\Traits\InstagramErrorTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\InstagramPublicationBatchStatus;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Repository\InstagramPublicationRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstagramPublicationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instagram_publication_source', fields: ['sourceType', 'sourceId'])]
#[ORM\Index(name: 'idx_instagram_publication_status', fields: ['status'])]
#[ORM\Index(name: 'idx_instagram_publication_dispatch', fields: ['status', 'lastDispatchedAt'])]
#[ORM\Index(name: 'idx_instagram_publication_last_attempt', fields: ['lastAttemptAt'])]
#[ORM\HasLifecycleCallbacks]
class InstagramPublication
{
    use InstagramErrorTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: InstagramPublicationSourceType::class)]
    private InstagramPublicationSourceType $sourceType;

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(length: 30, enumType: InstagramPublicationStatus::class)]
    private InstagramPublicationStatus $status = InstagramPublicationStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column]
    private int $totalMediaCount = 0;

    #[ORM\Column]
    private int $publishedMediaCount = 0;

    #[ORM\Column]
    private int $totalBatchCount = 0;

    #[ORM\Column]
    private int $publishedBatchCount = 0;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $firstAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastDispatchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $processingToken = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastErrorCode = null;

    /** @var Collection<int, InstagramPublicationBatch> */
    #[ORM\OneToMany(mappedBy: 'publication', targetEntity: InstagramPublicationBatch::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $batches;

    /** @var Collection<int, InstagramPublicationMedia> */
    #[ORM\OneToMany(mappedBy: 'publication', targetEntity: InstagramPublicationMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $media;

    public function __construct(
        InstagramPublicationSourceType $sourceType,
        int $sourceId,
        ?string $caption = null,
    ) {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->caption = self::normalizeInstagramText($caption);
        $this->batches = new ArrayCollection();
        $this->media = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceType(): InstagramPublicationSourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(InstagramPublicationSourceType $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    public function setSourceId(int $sourceId): static
    {
        $this->sourceId = $sourceId;

        return $this;
    }

    public function getStatus(): InstagramPublicationStatus
    {
        return $this->status;
    }

    public function setStatus(InstagramPublicationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            InstagramPublicationStatus::Published,
            InstagramPublicationStatus::NoMedia,
            InstagramPublicationStatus::LegacySkipped,
        ], true);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [
            InstagramPublicationStatus::Failed,
            InstagramPublicationStatus::PartialFailure,
        ], true);
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

    public function getTotalMediaCount(): int
    {
        return $this->totalMediaCount;
    }

    public function setTotalMediaCount(int $totalMediaCount): static
    {
        $this->totalMediaCount = max(0, $totalMediaCount);

        return $this;
    }

    public function getPublishedMediaCount(): int
    {
        return $this->publishedMediaCount;
    }

    public function setPublishedMediaCount(int $publishedMediaCount): static
    {
        $this->publishedMediaCount = max(0, $publishedMediaCount);

        return $this;
    }

    public function getTotalBatchCount(): int
    {
        return $this->totalBatchCount;
    }

    public function setTotalBatchCount(int $totalBatchCount): static
    {
        $this->totalBatchCount = max(0, $totalBatchCount);

        return $this;
    }

    public function getPublishedBatchCount(): int
    {
        return $this->publishedBatchCount;
    }

    public function setPublishedBatchCount(int $publishedBatchCount): static
    {
        $this->publishedBatchCount = max(0, $publishedBatchCount);

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

    public function getLastDispatchedAt(): ?DateTimeImmutable
    {
        return $this->lastDispatchedAt;
    }

    public function setLastDispatchedAt(?DateTimeImmutable $lastDispatchedAt): static
    {
        $this->lastDispatchedAt = $lastDispatchedAt;

        return $this;
    }

    public function markDispatched(?DateTimeImmutable $dispatchedAt = null): static
    {
        $this->lastDispatchedAt = $dispatchedAt ?? new DateTimeImmutable();

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

    public function getProcessingToken(): ?string
    {
        return $this->processingToken;
    }

    public function setProcessingToken(?string $processingToken): static
    {
        $this->processingToken = self::normalizeInstagramText($processingToken, 32);

        return $this;
    }

    public function clearProcessingToken(): static
    {
        $this->processingToken = null;

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

    /** @return Collection<int, InstagramPublicationBatch> */
    public function getBatches(): Collection
    {
        return $this->batches;
    }

    public function addBatch(InstagramPublicationBatch $batch): static
    {
        if (!$this->batches->contains($batch)) {
            $this->batches->add($batch);
        }

        if ($batch->getPublication() !== $this) {
            $batch->setPublication($this);
        }

        return $this;
    }

    public function removeBatch(InstagramPublicationBatch $batch): static
    {
        if ($this->batches->removeElement($batch) && $batch->getPublication() === $this) {
            $batch->setPublication(null);
        }

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

        if ($media->getPublication() !== $this) {
            $media->setPublication($this);
        }

        return $this;
    }

    public function removeMedia(InstagramPublicationMedia $media): static
    {
        if ($this->media->removeElement($media) && $media->getPublication() === $this) {
            $media->setPublication(null);
        }

        return $this;
    }

    public function synchronizeCounters(): static
    {
        $this->totalMediaCount = $this->media->count();
        $this->totalBatchCount = $this->batches->count();
        $this->publishedBatchCount = 0;
        $this->publishedMediaCount = 0;
        foreach ($this->batches as $batch) {
            $batch->synchronizeMediaCount();
            if ($batch->getStatus() !== InstagramPublicationBatchStatus::Published) {
                continue;
            }

            $this->publishedBatchCount++;
            $this->publishedMediaCount += $batch->getMediaCount();
        }

        return $this;
    }

}
