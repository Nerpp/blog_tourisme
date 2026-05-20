<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_article_city_visit', fields: ['article', 'cityVisitDraft'])]
#[ORM\Index(name: 'idx_article_city_visit_article', fields: ['article'])]
#[ORM\Index(name: 'idx_article_city_visit_city_visit', fields: ['cityVisitDraft'])]
#[ORM\Index(name: 'idx_article_city_visit_position', fields: ['position'])]
#[ORM\Index(name: 'idx_article_city_visit_role', fields: ['role'])]
#[ORM\HasLifecycleCallbacks]
class ArticleCityVisit
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'cityVisitLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: CityVisitDraft::class, inversedBy: 'articleLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CityVisitDraft $cityVisitDraft = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 30)]
    private string $role = 'related';

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

    public function getCityVisitDraft(): ?CityVisitDraft
    {
        return $this->cityVisitDraft;
    }

    public function setCityVisitDraft(CityVisitDraft $cityVisitDraft): static
    {
        $this->cityVisitDraft = $cityVisitDraft;

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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $role = trim($role);
        $this->role = $role !== '' ? $role : 'related';

        return $this;
    }
}
