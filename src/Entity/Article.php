<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\ContentStatus;
use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Index(name: 'idx_article_status', fields: ['status'])]
#[ORM\Index(name: 'idx_article_published_at', fields: ['publishedAt'])]
#[ORM\Index(name: 'idx_article_author', fields: ['author'])]
#[ORM\Index(name: 'idx_article_category', fields: ['category'])]
#[ORM\HasLifecycleCallbacks]
class Article
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(length: 180)]
    private ?string $title = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Draft;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'featuredArticles')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MediaAsset $featuredImage = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $seoDescription = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $canonicalUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, ArticleDestination> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticleDestination::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $destinationLinks;

    /** @var Collection<int, ArticlePlace> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticlePlace::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $placeLinks;

    /** @var Collection<int, ArticleHike> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticleHike::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $hikeLinks;

    /** @var Collection<int, ArticleCityVisit> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticleCityVisit::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $cityVisitLinks;

    /** @var Collection<int, ArticleMedia> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticleMedia::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $mediaLinks;

    /** @var Collection<int, ArticleTag> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticleTag::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $tagLinks;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: Comment::class)]
    #[ORM\OrderBy(['publishedAt' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $comments;

    public function __construct()
    {
        $this->destinationLinks = new ArrayCollection();
        $this->placeLinks = new ArrayCollection();
        $this->hikeLinks = new ArrayCollection();
        $this->cityVisitLinks = new ArrayCollection();
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
        return $this->title ?? sprintf('Article #%d', $this->id ?? 0);
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = $excerpt;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

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

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(?string $canonicalUrl): static
    {
        $this->canonicalUrl = $canonicalUrl;

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

    /** @return Collection<int, ArticleDestination> */
    public function getDestinationLinks(): Collection
    {
        return $this->destinationLinks;
    }

    /** @return Collection<int, ArticlePlace> */
    public function getPlaceLinks(): Collection
    {
        return $this->placeLinks;
    }

    /** @return Collection<int, ArticleHike> */
    public function getHikeLinks(): Collection
    {
        return $this->hikeLinks;
    }

    /** @return Collection<int, ArticleCityVisit> */
    public function getCityVisitLinks(): Collection
    {
        return $this->cityVisitLinks;
    }

    /** @return Collection<int, ArticleMedia> */
    public function getMediaLinks(): Collection
    {
        return $this->mediaLinks;
    }

    /** @return Collection<int, ArticleTag> */
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
