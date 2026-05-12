<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_city_visit_point_media', fields: ['cityVisitPoint', 'mediaAsset'])]
#[ORM\Index(name: 'idx_city_visit_point_media_point', fields: ['cityVisitPoint'])]
#[ORM\Index(name: 'idx_city_visit_point_media_media_asset', fields: ['mediaAsset'])]
#[ORM\HasLifecycleCallbacks]
class CityVisitPointMedia
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CityVisitPoint::class, inversedBy: 'mediaLinks')]
    #[ORM\JoinColumn(name: 'point_id', nullable: false, onDelete: 'CASCADE')]
    private ?CityVisitPoint $cityVisitPoint = null;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'cityVisitPointLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?MediaAsset $mediaAsset = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCityVisitPoint(): ?CityVisitPoint
    {
        return $this->cityVisitPoint;
    }

    public function setCityVisitPoint(?CityVisitPoint $cityVisitPoint): static
    {
        $this->cityVisitPoint = $cityVisitPoint;

        return $this;
    }

    public function getPoint(): ?CityVisitPoint
    {
        return $this->getCityVisitPoint();
    }

    public function setPoint(?CityVisitPoint $point): static
    {
        return $this->setCityVisitPoint($point);
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
