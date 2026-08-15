<?php

namespace App\Service\Facebook;

use RuntimeException;

final class FacebookPublisherException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaErrorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidConfiguration(): self
    {
        return new self('La configuration Facebook est invalide.');
    }

    public static function transport(): self
    {
        return new self('Facebook est temporairement inaccessible.');
    }

    public static function http(int $httpStatus, ?int $metaErrorCode = null): self
    {
        $message = null === $metaErrorCode
            ? sprintf('La requête Facebook a échoué (HTTP %d).', $httpStatus)
            : sprintf('La requête Facebook a échoué (HTTP %d, erreur Meta %d).', $httpStatus, $metaErrorCode);

        return new self($message, $httpStatus, $metaErrorCode);
    }

    public static function invalidResponse(): self
    {
        return new self('Facebook a renvoyé une réponse invalide.');
    }
}
