<?php

namespace App\Entity;

use App\Entity\Traits\InstagramErrorTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Repository\InstagramPublicationMediaRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstagramPublicationMediaRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instagram_publication_media_position', fields: ['publication', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_instagram_batch_media_position', fields: ['batch', 'batchPosition'])]
#[ORM\Index(name: 'idx_instagram_publication_media_asset', fields: ['mediaAsset'])]
#[ORM\Index(name: 'idx_instagram_publication_media_container', fields: ['containerId'])]
#[ORM\HasLifecycleCallbacks]
class InstagramPublicationMedia
{
    use InstagramErrorTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InstagramPublication::class, inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?InstagramPublication $publication = null;

    #[ORM\ManyToOne(targetEntity: InstagramPublicationBatch::class, inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?InstagramPublicationBatch $batch = null;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'instagramPublicationMedia')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?MediaAsset $mediaAsset = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceMediaId = null;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private int $batchPosition;

    #[ORM\Column(type: Types::TEXT)]
    private string $publicUrl;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $containerId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $containerCreatedAt = null;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastErrorCode = null;

    public function __construct(
        InstagramPublication $publication,
        InstagramPublicationBatch $batch,
        ?MediaAsset $mediaAsset,
        ?int $sourceMediaId,
        int $position,
        int $batchPosition,
        string $publicUrl,
        ?string $caption = null,
    ) {
        if ($batch->getPublication() !== $publication) {
            throw new \InvalidArgumentException('Le lot Instagram doit appartenir à la publication fournie.');
        }

        $this->publication = $publication;
        $this->batch = $batch;
        $this->sourceMediaId = $sourceMediaId ?? $mediaAsset?->getId();
        $this->position = $position;
        $this->batchPosition = $batchPosition;
        $this->publicUrl = self::normalizePublicUrl($publicUrl);
        $this->caption = self::normalizeInstagramText($caption);
        $this->setMediaAsset($mediaAsset);

        $publication->addMedia($this);
        $batch->addMedia($this);
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

    public function getBatch(): ?InstagramPublicationBatch
    {
        return $this->batch;
    }

    public function setBatch(?InstagramPublicationBatch $batch): static
    {
        $this->batch = $batch;

        return $this;
    }

    public function getMediaAsset(): ?MediaAsset
    {
        return $this->mediaAsset;
    }

    public function setMediaAsset(?MediaAsset $mediaAsset): static
    {
        if ($this->mediaAsset === $mediaAsset) {
            return $this;
        }

        $previousMediaAsset = $this->mediaAsset;
        $this->mediaAsset = $mediaAsset;
        $previousMediaAsset?->removeInstagramPublicationMedia($this);
        $mediaAsset?->addInstagramPublicationMedia($this);

        return $this;
    }

    public function releaseMediaAsset(): static
    {
        return $this->setMediaAsset(null);
    }

    public function getSourceMediaId(): ?int
    {
        return $this->sourceMediaId;
    }

    public function setSourceMediaId(?int $sourceMediaId): static
    {
        $this->sourceMediaId = $sourceMediaId;

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

    public function getBatchPosition(): int
    {
        return $this->batchPosition;
    }

    public function setBatchPosition(int $batchPosition): static
    {
        $this->batchPosition = $batchPosition;

        return $this;
    }

    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    public function setPublicUrl(string $publicUrl): static
    {
        $this->publicUrl = self::normalizePublicUrl($publicUrl);

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

    public function getContainerId(): ?string
    {
        return $this->containerId;
    }

    public function setContainerId(?string $containerId): static
    {
        $this->containerId = self::normalizeInstagramText($containerId, 255);

        return $this;
    }

    public function getContainerCreatedAt(): ?DateTimeImmutable
    {
        return $this->containerCreatedAt;
    }

    public function setContainerCreatedAt(?DateTimeImmutable $containerCreatedAt): static
    {
        $this->containerCreatedAt = $containerCreatedAt;

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
        $this->attemptCount++;
        $this->lastAttemptAt = $attemptedAt ?? new DateTimeImmutable();

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

    private static function normalizePublicUrl(string $publicUrl): string
    {
        $publicUrl = trim($publicUrl);
        $parts = parse_url($publicUrl);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
        ) {
            throw new \InvalidArgumentException('Une URL HTTPS publique absolue est requise pour le média Instagram.');
        }

        return $publicUrl;
    }
}
