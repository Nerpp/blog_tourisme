<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Tag
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $slug = null;

    /** @var Collection<int, ArticleTag> */
    #[ORM\OneToMany(mappedBy: 'tag', targetEntity: ArticleTag::class)]
    private Collection $articleTags;

    /** @var Collection<int, PlaceTag> */
    #[ORM\OneToMany(mappedBy: 'tag', targetEntity: PlaceTag::class)]
    private Collection $placeTags;

    public function __construct()
    {
        $this->articleTags = new ArrayCollection();
        $this->placeTags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->name ?? sprintf('Tag #%d', $this->id ?? 0);
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /** @return Collection<int, ArticleTag> */
    public function getArticleTags(): Collection
    {
        return $this->articleTags;
    }

    /** @return Collection<int, PlaceTag> */
    public function getPlaceTags(): Collection
    {
        return $this->placeTags;
    }
}
