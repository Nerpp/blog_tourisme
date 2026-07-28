<?php

namespace App\Service;

use App\Contract\CommentableContentInterface;
use App\Entity\Comment;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CommentTargetUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function createAction(CommentableContentInterface $content): string
    {
        return $this->urlGenerator->generate('app_comment_create', [
            'type' => $content->getCommentableType()->value,
            'slug' => (string) $content->getSlug(),
        ]);
    }

    public function forContent(CommentableContentInterface $content, string $fragment = 'comments'): string
    {
        $url = $this->urlGenerator->generate(
            $content->getCommentableType()->publicRoute(),
            ['slug' => (string) $content->getSlug()],
        );

        return $fragment === '' ? $url : $url.'#'.$fragment;
    }

    public function forComment(Comment $comment, string $fragment = 'comments'): ?string
    {
        $content = $comment->getThread()?->getContent();

        return $content instanceof CommentableContentInterface && $content->isPublished()
            ? $this->forContent($content, $fragment)
            : null;
    }
}
