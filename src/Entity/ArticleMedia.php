<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use App\Enum\MediaRole;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_article_media_role', fields: ['article', 'mediaAsset', 'role'])]
#[ORM\Index(name: 'idx_article_media_article', fields: ['article'])]
#[ORM\Index(name: 'idx_article_media_media_asset', fields: ['mediaAsset'])]
#[ORM\Index(name: 'idx_article_media_role', fields: ['role'])]
#[ORM\Index(name: 'idx_article_media_position', fields: ['position'])]
#[ORM\HasLifecycleCallbacks]
class ArticleMedia
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'mediaLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'articleLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?MediaAsset $mediaAsset = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 20, enumType: MediaRole::class)]
    private MediaRole $role = MediaRole::Content;

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

    public function getMediaAsset(): ?MediaAsset
    {
        return $this->mediaAsset;
    }

    public function setMediaAsset(MediaAsset $mediaAsset): static
    {
        $this->mediaAsset = $mediaAsset;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getRole(): MediaRole
    {
        return $this->role;
    }

    public function setRole(MediaRole $role): static
    {
        $this->role = $role;

        return $this;
    }
}
