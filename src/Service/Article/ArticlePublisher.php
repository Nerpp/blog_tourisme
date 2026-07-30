<?php

namespace App\Service\Article;

use App\Entity\Article;
use App\Enum\ContentStatus;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ArticlePublisher
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function publish(Article $article): ConstraintViolationListInterface
    {
        $violations = $this->validator->validate($article, null, ['Default', 'publish']);
        if (count($violations) > 0) {
            return $violations;
        }

        $article->setStatus(ContentStatus::Published);
        if ($article->getPublishedAt() === null) {
            $article->setPublishedAt(new \DateTimeImmutable());
        }

        return $violations;
    }
}
