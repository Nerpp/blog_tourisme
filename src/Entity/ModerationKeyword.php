<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\ModerationKeywordType;
use App\Repository\ModerationKeywordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ModerationKeywordRepository::class)]
#[ORM\Index(name: 'idx_moderation_keyword_type', fields: ['type'])]
#[ORM\Index(name: 'idx_moderation_keyword_enabled', fields: ['enabled'])]
#[ORM\HasLifecycleCallbacks]
class ModerationKeyword
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private ?string $keyword = null;

    #[ORM\Column(length: 20, enumType: ModerationKeywordType::class)]
    private ModerationKeywordType $type = ModerationKeywordType::Review;

    #[ORM\Column]
    private bool $enabled = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->keyword ?? sprintf('Mot-cle #%d', $this->id ?? 0);
    }

    public function getKeyword(): ?string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): static
    {
        $this->keyword = trim($keyword);

        return $this;
    }

    public function getType(): ModerationKeywordType
    {
        return $this->type;
    }

    public function setType(ModerationKeywordType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
