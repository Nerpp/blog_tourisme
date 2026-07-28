<?php

namespace App\Entity;

use App\Contract\CommentableContentInterface;
use App\Entity\Traits\CreatedAtTrait;
use App\Enum\CommentableType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_comment_thread_content_type', fields: ['contentType'])]
#[ORM\HasLifecycleCallbacks]
class CommentThread
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: CommentableType::class)]
    private CommentableType $contentType;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'thread', targetEntity: Comment::class)]
    #[ORM\OrderBy(['publishedAt' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $comments;

    #[ORM\OneToOne(mappedBy: 'commentThread', targetEntity: Article::class)]
    private ?Article $article = null;

    #[ORM\OneToOne(mappedBy: 'commentThread', targetEntity: Place::class)]
    private ?Place $place = null;

    #[ORM\OneToOne(mappedBy: 'commentThread', targetEntity: HikeDraft::class)]
    private ?HikeDraft $hike = null;

    #[ORM\OneToOne(mappedBy: 'commentThread', targetEntity: CityVisitDraft::class)]
    private ?CityVisitDraft $cityVisit = null;

    public function __construct(CommentableType $contentType)
    {
        $this->contentType = $contentType;
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentType(): CommentableType
    {
        return $this->contentType;
    }

    /** @return Collection<int, Comment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function getContent(): ?CommentableContentInterface
    {
        return match ($this->contentType) {
            CommentableType::Article => $this->article,
            CommentableType::Place => $this->place,
            CommentableType::Hike => $this->hike,
            CommentableType::CityVisit => $this->cityVisit,
        };
    }

    public function setArticle(Article $article): void
    {
        $this->assertType(CommentableType::Article);
        $this->article = $article;
    }

    public function setPlace(Place $place): void
    {
        $this->assertType(CommentableType::Place);
        $this->place = $place;
    }

    public function setHike(HikeDraft $hike): void
    {
        $this->assertType(CommentableType::Hike);
        $this->hike = $hike;
    }

    public function setCityVisit(CityVisitDraft $cityVisit): void
    {
        $this->assertType(CommentableType::CityVisit);
        $this->cityVisit = $cityVisit;
    }

    public function detachContent(CommentableContentInterface $content): void
    {
        if ($this->article === $content) {
            $this->article = null;
        } elseif ($this->place === $content) {
            $this->place = null;
        } elseif ($this->hike === $content) {
            $this->hike = null;
        } elseif ($this->cityVisit === $content) {
            $this->cityVisit = null;
        }
    }

    private function assertType(CommentableType $expectedType): void
    {
        if ($this->contentType !== $expectedType) {
            throw new \LogicException(sprintf(
                'Le fil %s ne peut pas être associé à un contenu %s.',
                $this->contentType->value,
                $expectedType->value,
            ));
        }
    }
}
