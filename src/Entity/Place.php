<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\ContentStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Repository\PlaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaceRepository::class)]
#[ORM\Index(name: 'idx_place_status', fields: ['status'])]
#[ORM\Index(name: 'idx_place_published_at', fields: ['publishedAt'])]
#[ORM\Index(name: 'idx_place_destination', fields: ['destination'])]
#[ORM\Index(name: 'idx_place_category', fields: ['category'])]
#[ORM\Index(name: 'idx_place_coordinates', fields: ['latitude', 'longitude'])]
#[ORM\HasLifecycleCallbacks]
class Place
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Destination::class, inversedBy: 'places')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Destination $destination = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'places')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(length: 180)]
    private ?string $name = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(nullable: true)]
    private ?int $visitDurationMinutes = null;

    #[ORM\Column(length: 20, nullable: true, enumType: PlaceDifficulty::class)]
    private ?PlaceDifficulty $difficulty = null;

    #[ORM\Column(length: 20, enumType: PriceType::class)]
    private PriceType $priceType = PriceType::Unknown;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Draft;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'featuredPlaces')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MediaAsset $featuredImage = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $seoDescription = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, ArticlePlace> */
    #[ORM\OneToMany(mappedBy: 'place', targetEntity: ArticlePlace::class)]
    private Collection $articleLinks;

    /** @var Collection<int, PlaceMedia> */
    #[ORM\OneToMany(mappedBy: 'place', targetEntity: PlaceMedia::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $mediaLinks;

    /** @var Collection<int, PlaceTag> */
    #[ORM\OneToMany(mappedBy: 'place', targetEntity: PlaceTag::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $tagLinks;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'place', targetEntity: Comment::class)]
    #[ORM\OrderBy(['publishedAt' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $comments;

    public function __construct()
    {
        $this->articleLinks = new ArrayCollection();
        $this->mediaLinks = new ArrayCollection();
        $this->tagLinks = new ArrayCollection();
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->name ?? sprintf('Lieu #%d', $this->id ?? 0);
    }

    public function getDestination(): ?Destination
    {
        return $this->destination;
    }

    public function setDestination(?Destination $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
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

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;

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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

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

    public function getVisitDurationMinutes(): ?int
    {
        return $this->visitDurationMinutes;
    }

    public function setVisitDurationMinutes(?int $visitDurationMinutes): static
    {
        $this->visitDurationMinutes = $visitDurationMinutes;

        return $this;
    }

    public function getDifficulty(): ?PlaceDifficulty
    {
        return $this->difficulty;
    }

    public function setDifficulty(?PlaceDifficulty $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getPriceType(): PriceType
    {
        return $this->priceType;
    }

    public function setPriceType(PriceType $priceType): static
    {
        $this->priceType = $priceType;

        return $this;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    public function setStatus(ContentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getFeaturedImage(): ?MediaAsset
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?MediaAsset $featuredImage): static
    {
        $this->featuredImage = $featuredImage;

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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    /** @return Collection<int, ArticlePlace> */
    public function getArticleLinks(): Collection
    {
        return $this->articleLinks;
    }

    /** @return Collection<int, PlaceMedia> */
    public function getMediaLinks(): Collection
    {
        return $this->mediaLinks;
    }

    /** @return Collection<int, PlaceTag> */
    public function getTagLinks(): Collection
    {
        return $this->tagLinks;
    }

    /** @return Collection<int, Comment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }
}
