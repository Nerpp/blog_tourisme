<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_article_tag', fields: ['article', 'tag'])]
#[ORM\Index(name: 'idx_article_tag_article', fields: ['article'])]
#[ORM\Index(name: 'idx_article_tag_tag', fields: ['tag'])]
#[ORM\HasLifecycleCallbacks]
class ArticleTag
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'tagLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'articleTags')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Tag $tag = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(Article $article): static
    {
        $this->article = $article;

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
