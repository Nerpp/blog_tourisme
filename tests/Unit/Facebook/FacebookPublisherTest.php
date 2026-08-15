<?php

namespace App\Tests\Unit\Facebook;

use App\Service\Facebook\FacebookPublisher;
use App\Service\Facebook\FacebookPublisherException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FacebookPublisherTest extends TestCase
{
    private const API_BASE_URL = 'https://graph.facebook.test/';
    private const API_VERSION = 'v26.0';
    private const PAGE_ID = '1278125198721340';
    private const TOKEN = 'test-facebook-page-token';

    public function testItPublishesALinkWithBearerAuthentication(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(
                self::API_BASE_URL.self::API_VERSION.'/'.self::PAGE_ID.'/feed',
                $url,
            );
            $this->assertBearerIsOnlyInHeaders($url, $options);
            self::assertSame([
                'message' => 'Découvrez cette randonnée.',
                'link' => 'https://estela-exploration.fr/randonnees/test',
            ], $this->requestBody($options));

            return new MockResponse('{"id":"1278125198721340_123456789"}');
        }, self::API_BASE_URL);

        $publicationId = $this->publisher($client)->publishLink(
            'Découvrez cette randonnée.',
            'https://estela-exploration.fr/randonnees/test',
        );

        self::assertSame('1278125198721340_123456789', $publicationId);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItRejectsAnEmptyMessageBeforeAnyRequest(): void
    {
        foreach (['', '   '] as $message) {
            $client = new MockHttpClient([new MockResponse('{"id":"unused"}')], self::API_BASE_URL);

            try {
                $this->publisher($client)->publishLink(
                    $message,
                    'https://estela-exploration.fr/randonnees/test',
                );
                self::fail('Une InvalidArgumentException était attendue pour le message vide.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $client->getRequestsCount());
            }
        }
    }

    public function testItRejectsAnEmptyOrInvalidUrlBeforeAnyRequest(): void
    {
        foreach (['', 'pas-une-url'] as $url) {
            $client = new MockHttpClient([new MockResponse('{"id":"unused"}')], self::API_BASE_URL);

            try {
                $this->publisher($client)->publishLink('Une publication.', $url);
                self::fail('Une InvalidArgumentException était attendue pour le lien invalide.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $client->getRequestsCount());
            }
        }
    }

    public function testItRejectsAnHttpUrlBeforeAnyRequest(): void
    {
        $client = new MockHttpClient([new MockResponse('{"id":"unused"}')], self::API_BASE_URL);

        try {
            $this->publisher($client)->publishLink(
                'Une publication.',
                'http://estela-exploration.fr/randonnees/test',
            );
            self::fail('Une InvalidArgumentException était attendue pour le lien HTTP.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testItSanitizesGraphApiErrors(): void
    {
        $body = json_encode([
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 400]),
        ], self::API_BASE_URL);

        try {
            $this->publisher($client)->publishLink(
                'Une publication.',
                'https://estela-exploration.fr/randonnees/test',
            );
            self::fail('Une FacebookPublisherException était attendue pour l’erreur Graph API.');
        } catch (FacebookPublisherException $exception) {
            self::assertSame(400, $exception->httpStatus);
            self::assertSame(190, $exception->metaErrorCode);
            self::assertStringContainsString('HTTP 400', $exception->getMessage());
            self::assertStringContainsString('Meta 190', $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItRejectsASuccessfulResponseWithoutAnId(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"success":true}', ['http_code' => 200]),
        ], self::API_BASE_URL);

        try {
            $this->publisher($client)->publishLink(
                'Une publication.',
                'https://estela-exploration.fr/randonnees/test',
            );
            self::fail('Une FacebookPublisherException était attendue pour l’identifiant absent.');
        } catch (FacebookPublisherException $exception) {
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @param array<mixed> $options */
    private function assertBearerIsOnlyInHeaders(string $url, array $options): void
    {
        self::assertStringNotContainsString(self::TOKEN, $url);
        self::assertStringNotContainsString('access_token', strtolower($url));

        $headers = $options['headers'] ?? null;
        if (!is_array($headers)) {
            self::fail('Les en-têtes HTTP normalisés sont absents.');
        }

        $headerLines = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                self::fail('Un en-tête HTTP normalisé est invalide.');
            }

            $headerLines[] = $header;
        }

        self::assertStringContainsString(
            'Authorization: Bearer '.self::TOKEN,
            implode("\n", $headerLines),
        );

        $body = $options['body'] ?? null;
        if (!is_string($body)) {
            self::fail('Le corps HTTP normalisé est absent.');
        }

        self::assertStringNotContainsString(self::TOKEN, $body);
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, string>
     */
    private function requestBody(array $options): array
    {
        $encodedBody = $options['body'] ?? null;
        if (!is_string($encodedBody)) {
            self::fail('Le corps HTTP normalisé est absent.');
        }

        parse_str($encodedBody, $parsedBody);

        $body = [];
        foreach ($parsedBody as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                self::fail('Le corps HTTP normalisé ne contient pas des champs scalaires valides.');
            }

            $body[$key] = $value;
        }

        return $body;
    }

    private function publisher(HttpClientInterface $httpClient): FacebookPublisher
    {
        return new FacebookPublisher(
            httpClient: $httpClient,
            facebookPageId: self::PAGE_ID,
            facebookPageAccessToken: self::TOKEN,
            apiVersion: self::API_VERSION,
        );
    }
}
