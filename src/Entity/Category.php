<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\CategoryType;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Index(name: 'idx_category_type', fields: ['type'])]
#[ORM\HasLifecycleCallbacks]
class Category
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

    #[ORM\Column(length: 20, enumType: CategoryType::class)]
    private CategoryType $type = CategoryType::Both;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Article::class)]
    private Collection $articles;

    /** @var Collection<int, Place> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Place::class)]
    private Collection $places;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->places = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->name ?? sprintf('Categorie #%d', $this->id ?? 0);
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

    public function getType(): CategoryType
    {
        return $this->type;
    }

    public function setType(CategoryType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    /** @return Collection<int, Place> */
    public function getPlaces(): Collection
    {
        return $this->places;
    }
}
