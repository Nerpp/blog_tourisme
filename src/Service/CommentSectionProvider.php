<?php

namespace App\Service;

use App\Contract\CommentableContentInterface;
use App\Entity\Comment;
use App\Entity\User;
use App\Form\CommentType;
use App\Repository\CommentRepository;
use App\View\CommentSectionView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

final class CommentSectionProvider
{
    public function __construct(
        private readonly CommentRepository $commentRepository,
        private readonly CommentReactionViewService $reactionViewService,
        private readonly CommentTargetUrlGenerator $urlGenerator,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function provide(
        CommentableContentInterface $content,
        Request $request,
        mixed $viewer,
        bool $allowComment = true,
    ): CommentSectionView {
        $user = $viewer instanceof User ? $viewer : null;
        $sort = $this->sort($request);
        $comments = $this->commentRepository->findApprovedForThread($content->getCommentThread(), $user, $sort);
        $reactionContext = $this->reactionViewService->buildContext($comments, $user);
        $form = null;

        if ($user instanceof User && $allowComment && $content->isPublished()) {
            $form = $this->formFactory->create(CommentType::class, new Comment(), [
                'action' => $this->urlGenerator->createAction($content),
                'method' => 'POST',
            ])->createView();
        }

        return new CommentSectionView(
            comments: $comments,
            form: $form,
            sort: $sort,
            count: $this->commentRepository->countVisibleForThread($content->getCommentThread()),
            likeCounts: $reactionContext['like_counts'],
            likedCommentIds: $reactionContext['liked_comment_ids'],
        );
    }

    private function sort(Request $request): string
    {
        $sort = $request->query->getString('comments_sort', 'recent');

        return in_array($sort, ['recent', 'popular'], true) ? $sort : 'recent';
    }
}
