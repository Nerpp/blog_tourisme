<?php

namespace App\Message;

final readonly class PublishFacebookContent
{
    public function __construct(
        public int $publicationId,
        public string $executionId,
    ) {
        if ($this->publicationId < 1) {
            throw new \InvalidArgumentException('The Facebook publication id must be positive.');
        }

        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $this->executionId)) {
            throw new \InvalidArgumentException('The Facebook execution id must be a 32-character hexadecimal value.');
        }
    }

    public static function forPublication(int $publicationId): self
    {
        return new self($publicationId, bin2hex(random_bytes(16)));
    }
}
