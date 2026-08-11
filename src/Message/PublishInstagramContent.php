<?php

namespace App\Message;

final readonly class PublishInstagramContent
{
    public function __construct(
        public int $publicationId,
        public string $executionId,
    ) {
        if ($this->publicationId < 1) {
            throw new \InvalidArgumentException('The Instagram publication id must be positive.');
        }

        if (preg_match('/^[a-f0-9]{32}$/', $this->executionId) !== 1) {
            throw new \InvalidArgumentException('The Instagram execution id must be a 32-character hexadecimal value.');
        }
    }

    public static function forPublication(int $publicationId): self
    {
        return new self($publicationId, bin2hex(random_bytes(16)));
    }
}
