<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_hike_point_media', fields: ['hikePoint', 'mediaAsset'])]
#[ORM\Index(name: 'idx_hike_point_media_point', fields: ['hikePoint'])]
#[ORM\Index(name: 'idx_hike_point_media_media_asset', fields: ['mediaAsset'])]
#[ORM\HasLifecycleCallbacks]
class HikePointMedia
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HikePoint::class, inversedBy: 'mediaLinks')]
    #[ORM\JoinColumn(name: 'point_id', nullable: false, onDelete: 'CASCADE')]
    private ?HikePoint $hikePoint = null;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'hikePointLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?MediaAsset $mediaAsset = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHikePoint(): ?HikePoint
    {
        return $this->hikePoint;
    }

    public function setHikePoint(?HikePoint $hikePoint): static
    {
        $this->hikePoint = $hikePoint;

        return $this;
    }

    public function getPoint(): ?HikePoint
    {
        return $this->getHikePoint();
    }

    public function setPoint(?HikePoint $point): static
    {
        return $this->setHikePoint($point);
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
}
