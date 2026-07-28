<?php

namespace App\View;

use App\Entity\Comment;
use Symfony\Component\Form\FormView;

final readonly class CommentSectionView
{
    /**
     * @param list<Comment>    $comments
     * @param array<int, int> $likeCounts
     * @param list<int>       $likedCommentIds
     */
    public function __construct(
        public array $comments,
        public ?FormView $form,
        public string $sort,
        public int $count,
        public array $likeCounts,
        public array $likedCommentIds,
    ) {
    }
}
