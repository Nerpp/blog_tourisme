<?php

namespace App\Tests\Unit\Article;

use App\Entity\Article;
use App\Enum\ContentStatus;
use App\Service\Article\ArticlePublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ArticlePublisherTest extends TestCase
{
    public function testItPublishesAValidArticleAndSetsItsFirstPublicationDate(): void
    {
        $article = new Article();
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with($article, null, ['Default', 'publish'])
            ->willReturn(new ConstraintViolationList());

        $violations = (new ArticlePublisher($validator))->publish($article);

        self::assertCount(0, $violations);
        self::assertSame(ContentStatus::Published, $article->getStatus());
        self::assertNotNull($article->getPublishedAt());
    }

    public function testItDoesNotChangeStatusOrDateWhenPublicationValidationFails(): void
    {
        $article = new Article();
        $violation = new ConstraintViolation('Publication refusée.', null, [], $article, '', null);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with($article, null, ['Default', 'publish'])
            ->willReturn(new ConstraintViolationList([$violation]));

        $violations = (new ArticlePublisher($validator))->publish($article);

        self::assertCount(1, $violations);
        self::assertSame(ContentStatus::Draft, $article->getStatus());
        self::assertNull($article->getPublishedAt());
    }

    public function testItPreservesTheFirstPublicationDateOnLaterPublications(): void
    {
        $publishedAt = new \DateTimeImmutable('-2 days');
        $article = (new Article())
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt($publishedAt);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with($article, null, ['Default', 'publish'])
            ->willReturn(new ConstraintViolationList());

        (new ArticlePublisher($validator))->publish($article);

        self::assertSame($publishedAt, $article->getPublishedAt());
    }
}
