<?php

namespace App\Service\Instagram;

use Closure;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ValueError;

final class InstagramPublisher
{
    private const DEFAULT_API_BASE_URL = 'https://graph.instagram.com';

    private readonly string $instagramUserId;
    private readonly string $instagramAccessToken;
    private readonly string $apiBaseUrl;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $instagramUserId,
        string $instagramAccessToken,
        private readonly int $pollAttempts = 6,
        private readonly int $pollDelayMilliseconds = 2_000,
        private readonly float $requestTimeoutSeconds = 15.0,
        ?Closure $sleeper = null,
        string $apiBaseUrl = self::DEFAULT_API_BASE_URL,
    ) {
        $instagramUserId = trim($instagramUserId);
        $apiBaseUrl = rtrim(trim($apiBaseUrl), '/');

        if (!$this->isIdentifier($instagramUserId)
            || '' === $instagramAccessToken
            || 1 === preg_match('/[^\x21-\x7E]/', $instagramAccessToken)
            || $pollAttempts < 1
            || $pollDelayMilliseconds < 0
            || $requestTimeoutSeconds <= 0
            || !is_finite($requestTimeoutSeconds)
            || !$this->isValidApiBaseUrl($apiBaseUrl)
        ) {
            throw InstagramPublisherException::invalidConfiguration();
        }

        $this->instagramUserId = $instagramUserId;
        $this->instagramAccessToken = $instagramAccessToken;
        $this->apiBaseUrl = $apiBaseUrl;
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1_000);
            }
        };
    }

    public function createImageContainer(
        string $imageUrl,
        ?string $caption = null,
        bool $carouselItem = false,
    ): string {
        if (!$this->isValidPublicImageUrl($imageUrl)) {
            throw InstagramPublisherException::invalidArgument();
        }

        $body = ['image_url' => $imageUrl];

        if (null !== $caption) {
            $body['caption'] = $caption;
        }

        if ($carouselItem) {
            $body['is_carousel_item'] = 'true';
        }

        return $this->responseId($this->requestJson('POST', $this->mediaUrl(), [
            'body' => $body,
        ]));
    }

    /**
     * @param list<string> $childContainerIds
     */
    public function createCarouselContainer(array $childContainerIds, string $caption): string
    {
        if (count($childContainerIds) < 2 || count($childContainerIds) > 10) {
            throw InstagramPublisherException::invalidArgument();
        }

        foreach ($childContainerIds as $childContainerId) {
            if (!$this->isIdentifier($childContainerId)) {
                throw InstagramPublisherException::invalidArgument();
            }
        }

        return $this->responseId($this->requestJson('POST', $this->mediaUrl(), [
            'body' => [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $childContainerIds),
                'caption' => $caption,
            ],
        ]));
    }

    public function getContainerStatus(string $containerId): InstagramContainerStatus
    {
        $containerId = $this->validatedIdentifier($containerId);
        $response = $this->requestJson('GET', sprintf('%s/%s', $this->apiBaseUrl, rawurlencode($containerId)), [
            'query' => ['fields' => 'status_code,status'],
        ]);

        $statusCode = $response['status_code'] ?? null;
        if (!is_string($statusCode)) {
            throw InstagramPublisherException::invalidResponse();
        }

        try {
            return InstagramContainerStatus::from(strtoupper(trim($statusCode)));
        } catch (ValueError) {
            throw InstagramPublisherException::invalidResponse();
        }
    }

    public function waitUntilReady(string $containerId): InstagramContainerStatus
    {
        for ($attempt = 1; $attempt <= $this->pollAttempts; ++$attempt) {
            $status = $this->getContainerStatus($containerId);

            if ($status->isReady()) {
                return $status;
            }

            if (InstagramContainerStatus::Error === $status) {
                throw InstagramPublisherException::containerError();
            }

            if (InstagramContainerStatus::Expired === $status) {
                throw InstagramPublisherException::containerExpired();
            }

            if ($attempt < $this->pollAttempts) {
                ($this->sleeper)($this->pollDelayMilliseconds);
            }
        }

        throw InstagramPublisherException::pollingTimeout();
    }

    public function publishContainer(string $containerId): string
    {
        $containerId = $this->validatedIdentifier($containerId);

        return $this->responseId($this->requestJson('POST', $this->mediaPublishUrl(), [
            'body' => ['creation_id' => $containerId],
        ]));
    }

    private function mediaUrl(): string
    {
        return sprintf('%s/%s/media', $this->apiBaseUrl, rawurlencode($this->instagramUserId));
    }

    private function mediaPublishUrl(): string
    {
        return sprintf('%s/%s/media_publish', $this->apiBaseUrl, rawurlencode($this->instagramUserId));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options = []): array
    {
        $options['auth_bearer'] = $this->instagramAccessToken;
        $options['headers'] = ['Accept' => 'application/json'];
        $options['timeout'] = $this->requestTimeoutSeconds;
        $options['max_duration'] = $this->requestTimeoutSeconds;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $httpStatus = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            // Do not retain the transport exception: its message can contain request details.
            throw InstagramPublisherException::transport();
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            [$metaErrorCode, $metaErrorSubcode] = $this->metaErrorCodes($content);

            throw InstagramPublisherException::http($httpStatus, $metaErrorCode, $metaErrorSubcode);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InstagramPublisherException::invalidResponse();
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw InstagramPublisherException::invalidResponse();
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array{?int, ?int}
     */
    private function metaErrorCodes(string $content): array
    {
        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [null, null];
        }

        if (!is_array($decoded) || !is_array($decoded['error'] ?? null)) {
            return [null, null];
        }

        return [
            $this->safeInteger($decoded['error']['code'] ?? null),
            $this->safeInteger($decoded['error']['error_subcode'] ?? null),
        ];
    }

    private function safeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && 1 === preg_match('/^[0-9]+$/D', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseId(array $response): string
    {
        $id = $response['id'] ?? null;

        if (is_int($id)) {
            $id = (string) $id;
        }

        if (!is_string($id) || !$this->isIdentifier($id)) {
            throw InstagramPublisherException::invalidResponse();
        }

        return $id;
    }

    private function validatedIdentifier(string $identifier): string
    {
        if (!$this->isIdentifier($identifier)) {
            throw InstagramPublisherException::invalidArgument();
        }

        return $identifier;
    }

    private function isIdentifier(string $identifier): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._-]+$/D', $identifier);
    }

    private function isValidPublicImageUrl(string $imageUrl): bool
    {
        $parts = parse_url($imageUrl);

        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && is_string($parts['host'] ?? null)
            && '' !== $parts['host']
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function isValidApiBaseUrl(string $apiBaseUrl): bool
    {
        $parts = parse_url($apiBaseUrl);

        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && is_string($parts['host'] ?? null)
            && '' !== $parts['host']
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }
}
