<?php

namespace App\Tests\Unit\Facebook;

use App\Message\PublishFacebookContent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublishFacebookContentTest extends TestCase
{
    public function testItKeepsAValidPublicationAndExecutionId(): void
    {
        $message = new PublishFacebookContent(42, '0123456789abcdef0123456789abcdef');

        self::assertSame(42, $message->publicationId);
        self::assertSame('0123456789abcdef0123456789abcdef', $message->executionId);
    }

    public function testItRejectsANonPositivePublicationId(): void
    {
        foreach ([0, -1] as $publicationId) {
            $caught = false;
            try {
                new PublishFacebookContent($publicationId, '0123456789abcdef0123456789abcdef');
            } catch (InvalidArgumentException) {
                $caught = true;
            }

            self::assertTrue($caught, 'Une InvalidArgumentException était attendue pour l’identifiant.');
        }
    }

    public function testItRejectsAnInvalidExecutionId(): void
    {
        foreach (['', 'abc', 'GGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGG'] as $executionId) {
            $caught = false;
            try {
                new PublishFacebookContent(42, $executionId);
            } catch (InvalidArgumentException) {
                $caught = true;
            }

            self::assertTrue($caught, 'Une InvalidArgumentException était attendue pour l’executionId.');
        }
    }

    public function testForPublicationGeneratesAValidExecutionId(): void
    {
        $message = PublishFacebookContent::forPublication(42);

        self::assertSame(42, $message->publicationId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $message->executionId);
    }
}
