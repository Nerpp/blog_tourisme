<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_place_tag', fields: ['place', 'tag'])]
#[ORM\Index(name: 'idx_place_tag_place', fields: ['place'])]
#[ORM\Index(name: 'idx_place_tag_tag', fields: ['tag'])]
#[ORM\HasLifecycleCallbacks]
class PlaceTag
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Place::class, inversedBy: 'tagLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Place $place = null;

    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'placeTags')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Tag $tag = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlace(): ?Place
    {
        return $this->place;
    }

    public function setPlace(Place $place): static
    {
        $this->place = $place;

        return $this;
    }

    public function getTag(): ?Tag
    {
        return $this->tag;
    }

    public function setTag(Tag $tag): static
    {
        $this->tag = $tag;

        return $this;
    }
}
