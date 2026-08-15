<?php

namespace App\Service\Social;

use App\Entity\FacebookPublication;
use App\Entity\InstagramPublication;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PreparedSocialPublications
{
    public function __construct(
        public ?InstagramPublication $instagram,
        public ?FacebookPublication $facebook,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->instagram && null === $this->facebook;
    }
}
