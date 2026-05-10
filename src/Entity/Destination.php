<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\DestinationType;
use App\Repository\DestinationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DestinationRepository::class)]
#[ORM\Index(name: 'idx_destination_type', fields: ['type'])]
#[ORM\Index(name: 'idx_destination_parent', fields: ['parent'])]
#[ORM\Index(name: 'idx_destination_coordinates', fields: ['latitude', 'longitude'])]
#[ORM\HasLifecycleCallbacks]
class Destination
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, Destination> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 20, enumType: DestinationType::class)]
    private DestinationType $type = DestinationType::Area;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $seoDescription = null;

    /** @var Collection<int, Place> */
    #[ORM\OneToMany(mappedBy: 'destination', targetEntity: Place::class)]
    private Collection $places;

    /** @var Collection<int, ArticleDestination> */
    #[ORM\OneToMany(mappedBy: 'destination', targetEntity: ArticleDestination::class)]
    private Collection $articleLinks;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->places = new ArrayCollection();
        $this->articleLinks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Destination> */
    public function getChildren(): Collection
    {
        return $this->children;
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

    public function getType(): DestinationType
    {
        return $this->type;
    }

    public function setType(DestinationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

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

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = $seoTitle;

        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = $seoDescription;

        return $this;
    }

    /** @return Collection<int, Place> */
    public function getPlaces(): Collection
    {
        return $this->places;
    }

    /** @return Collection<int, ArticleDestination> */
    public function getArticleLinks(): Collection
    {
        return $this->articleLinks;
    }
}
