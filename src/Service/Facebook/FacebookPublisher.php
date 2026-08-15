<?php

namespace App\Service\Facebook;

use InvalidArgumentException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FacebookPublisher
{
    private readonly string $facebookPageId;
    private readonly string $facebookPageAccessToken;
    private readonly string $apiVersion;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $facebookPageId,
        string $facebookPageAccessToken,
        string $apiVersion,
    ) {
        $facebookPageId = trim($facebookPageId);
        $apiVersion = trim($apiVersion);

        if (
            1 !== preg_match('/^[0-9]+$/D', $facebookPageId)
            || '' === $facebookPageAccessToken
            || 1 === preg_match('/[^\x21-\x7E]/', $facebookPageAccessToken)
            || 1 !== preg_match('/^v[1-9][0-9]*\.[0-9]+$/D', $apiVersion)
        ) {
            throw FacebookPublisherException::invalidConfiguration();
        }

        $this->facebookPageId = $facebookPageId;
        $this->facebookPageAccessToken = $facebookPageAccessToken;
        $this->apiVersion = $apiVersion;
    }

    public function publishLink(string $message, string $url): string
    {
        $message = trim($message);
        $url = trim($url);

        if ('' === $message) {
            throw new InvalidArgumentException('Le message Facebook ne peut pas être vide.');
        }

        if (!$this->isValidHttpsUrl($url)) {
            throw new InvalidArgumentException('Le lien Facebook doit être une URL HTTPS valide.');
        }

        try {
            $response = $this->httpClient->request('POST', $this->feedEndpoint(), [
                'auth_bearer' => $this->facebookPageAccessToken,
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'message' => $message,
                    'link' => $url,
                ],
            ]);
            $httpStatus = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            // Do not retain the transport exception: its message can contain request details.
            throw FacebookPublisherException::transport();
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw FacebookPublisherException::http($httpStatus, $this->metaErrorCode($content));
        }

        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FacebookPublisherException::invalidResponse();
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw FacebookPublisherException::invalidResponse();
        }

        $id = $decoded['id'] ?? null;
        if (!is_string($id) || 1 !== preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
            throw FacebookPublisherException::invalidResponse();
        }

        return $id;
    }

    private function feedEndpoint(): string
    {
        return sprintf('%s/%s/feed', $this->apiVersion, $this->facebookPageId);
    }

    private function isValidHttpsUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && is_string($parts['host'] ?? null)
            && '' !== $parts['host']
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function metaErrorCode(string $content): ?int
    {
        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !is_array($decoded['error'] ?? null)) {
            return null;
        }

        $code = $decoded['error']['code'] ?? null;
        if (is_int($code)) {
            return $code;
        }

        if (is_string($code) && 1 === preg_match('/^[0-9]+$/D', $code)) {
            return (int) $code;
        }

        return null;
    }
}
