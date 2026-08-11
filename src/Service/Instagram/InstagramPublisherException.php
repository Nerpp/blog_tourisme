<?php

namespace App\Service\Instagram;

use RuntimeException;

final class InstagramPublisherException extends RuntimeException
{
    private function __construct(
        public readonly InstagramPublisherFailure $failure,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaErrorCode = null,
        public readonly ?int $metaErrorSubcode = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidConfiguration(): self
    {
        return new self(
            InstagramPublisherFailure::InvalidConfiguration,
            'La configuration Instagram est invalide.',
        );
    }

    public static function invalidArgument(): self
    {
        return new self(
            InstagramPublisherFailure::InvalidArgument,
            'Les paramètres de publication Instagram sont invalides.',
        );
    }

    public static function transport(): self
    {
        return new self(
            InstagramPublisherFailure::Transport,
            'Instagram est temporairement inaccessible.',
        );
    }

    public static function http(
        int $httpStatus,
        ?int $metaErrorCode = null,
        ?int $metaErrorSubcode = null,
    ): self {
        return new self(
            InstagramPublisherFailure::Http,
            sprintf('La requête Instagram a échoué (HTTP %d).', $httpStatus),
            $httpStatus,
            $metaErrorCode,
            $metaErrorSubcode,
        );
    }

    public static function invalidResponse(): self
    {
        return new self(
            InstagramPublisherFailure::InvalidResponse,
            'Instagram a renvoyé une réponse invalide.',
        );
    }

    public static function containerError(): self
    {
        return new self(
            InstagramPublisherFailure::ContainerError,
            'Instagram n’a pas pu préparer le média.',
        );
    }

    public static function containerExpired(): self
    {
        return new self(
            InstagramPublisherFailure::ContainerExpired,
            'Le conteneur Instagram a expiré avant sa publication.',
        );
    }

    public static function pollingTimeout(): self
    {
        return new self(
            InstagramPublisherFailure::PollingTimeout,
            'Le délai de préparation du média Instagram a été dépassé.',
        );
    }
}
