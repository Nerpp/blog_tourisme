<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use App\Enum\MediaRole;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_hike_draft_media_role', fields: ['hikeDraft', 'mediaAsset', 'role'])]
#[ORM\Index(name: 'idx_hike_draft_media_hike_draft', fields: ['hikeDraft'])]
#[ORM\Index(name: 'idx_hike_draft_media_media_asset', fields: ['mediaAsset'])]
#[ORM\Index(name: 'idx_hike_draft_media_role', fields: ['role'])]
#[ORM\Index(name: 'idx_hike_draft_media_position', fields: ['position'])]
#[ORM\HasLifecycleCallbacks]
class HikeDraftMedia
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HikeDraft::class, inversedBy: 'mediaLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?HikeDraft $hikeDraft = null;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'hikeDraftLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?MediaAsset $mediaAsset = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 20, enumType: MediaRole::class)]
    private MediaRole $role = MediaRole::Gallery;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHikeDraft(): ?HikeDraft
    {
        return $this->hikeDraft;
    }

    public function setHikeDraft(HikeDraft $hikeDraft): static
    {
        $this->hikeDraft = $hikeDraft;

        return $this;
    }

    public function getMediaAsset(): ?MediaAsset
    {
        return $this->mediaAsset;
    }

    public function setMediaAsset(MediaAsset $mediaAsset): static
    {
        $this->mediaAsset = $mediaAsset;

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

    public function getRole(): MediaRole
    {
        return $this->role;
    }

    public function setRole(MediaRole $role): static
    {
        $this->role = $role;

        return $this;
    }
}
