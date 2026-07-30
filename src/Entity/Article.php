<?php

namespace App\Entity;

use App\Contract\CommentableContentInterface;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\CommentableType;
use App\Enum\ContentStatus;
use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[UniqueEntity(
    fields: ['slug'],
    message: 'Ce slug est déjà utilisé par un autre article.',
    groups: ['publish'],
)]
#[ORM\Index(name: 'idx_article_status', fields: ['status'])]
#[ORM\Index(name: 'idx_article_published_at', fields: ['publishedAt'])]
#[ORM\Index(name: 'idx_article_author', fields: ['author'])]
#[ORM\Index(name: 'idx_article_category', fields: ['category'])]
#[ORM\HasLifecycleCallbacks]
class Article implements CommentableContentInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'Un auteur doit être associé à l’article.', groups: ['Default'])]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'La catégorie est obligatoire pour publier l’article.', groups: ['publish'])]
    private ?Category $category = null;

    #[ORM\Column(length: 180)]
    #[Assert\Length(
        max: 180,
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.',
        groups: ['Default'],
    )]
    #[Assert\NotBlank(message: 'Le titre est obligatoire pour publier l’article.', groups: ['publish'])]
    private ?string $title = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\Length(
        max: 180,
        maxMessage: 'Le slug ne peut pas dépasser {{ limit }} caractères.',
        groups: ['Default'],
    )]
    #[Assert\NotBlank(message: 'Un slug valide est obligatoire pour publier l’article.', groups: ['publish'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(
        max: 1000,
        maxMessage: 'Le résumé ne peut pas dépasser {{ limit }} caractères.',
        groups: ['Default'],
    )]
    #[Assert\NotBlank(message: 'Le résumé est obligatoire pour publier l’article.', groups: ['publish'])]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\Length(
        max: 200000,
        maxMessage: 'Le contenu ne peut pas dépasser {{ limit }} caractères.',
        groups: ['Default'],
    )]
    #[Assert\NotBlank(message: 'Le contenu de l’article est obligatoire pour le publier.', groups: ['publish'])]
    private ?string $content = null;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Draft;

    #[ORM\OneToOne(inversedBy: 'article', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CommentThread $commentThread;

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

    public function __construct()
    {
        $this->destinationLinks = new ArrayCollection();
        $this->placeLinks = new ArrayCollection();
        $this->hikeLinks = new ArrayCollection();
        $this->cityVisitLinks = new ArrayCollection();
        $this->mediaLinks = new ArrayCollection();
        $this->tagLinks = new ArrayCollection();
        $this->setCommentThread(new CommentThread(CommentableType::Article));
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

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    public function getCommentableTitle(): string
    {
        return (string) $this->title;
    }

    public function getCommentableType(): CommentableType
    {
        return CommentableType::Article;
    }

    public function getCommentThread(): CommentThread
    {
        return $this->commentThread;
    }

    public function setCommentThread(CommentThread $commentThread): static
    {
        if ($commentThread->getContentType() !== CommentableType::Article) {
            throw new \LogicException('Un article doit utiliser un fil de commentaires de type article.');
        }

        $this->commentThread = $commentThread;
        $commentThread->setArticle($this);

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

    #[Assert\Callback(groups: ['publish'])]
    public function validatePublicationContent(ExecutionContextInterface $context): void
    {
        $rawContent = (string) $this->content;
        if (trim($rawContent) === '') {
            return;
        }

        $content = preg_replace('/\[\[media:\d+\]\]/', '', $rawContent) ?? $rawContent;
        $plainContent = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainContent = preg_replace('/[\s\x{00A0}]+/u', '', $plainContent) ?? $plainContent;

        if ($plainContent === '') {
            $context->buildViolation('Le contenu de l’article doit contenir du texte pour être publié.')
                ->atPath('content')
                ->addViolation();
        }
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
        return $this->commentThread->getComments();
    }
}
