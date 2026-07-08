<?php

namespace App\Entity;

use App\Repository\ProductionAccessBootstrapRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductionAccessBootstrapRepository::class)]
class ProductionAccessBootstrap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $completedAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $configurationFingerprint = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $executedFromFile = null;

    public function __construct()
    {
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getConfigurationFingerprint(): ?string
    {
        return $this->configurationFingerprint;
    }

    public function setConfigurationFingerprint(?string $configurationFingerprint): static
    {
        $this->configurationFingerprint = $configurationFingerprint;

        return $this;
    }

    public function getExecutedFromFile(): ?string
    {
        return $this->executedFromFile;
    }

    public function setExecutedFromFile(?string $executedFromFile): static
    {
        $this->executedFromFile = $executedFromFile === null ? null : mb_substr($executedFromFile, 0, 500);

        return $this;
    }
}
