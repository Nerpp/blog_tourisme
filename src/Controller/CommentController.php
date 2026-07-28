<?php

namespace App\Controller;

use App\Contract\CommentableContentInterface;
use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentableType;
use App\Enum\CommentReportReason;
use App\Enum\CommentStatus;
use App\Form\CommentType;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentReportRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\CommentVoter;
use App\Service\CommentDeletionService;
use App\Service\CommentableContentResolver;
use App\Service\CommentManager;
use App\Service\CommentModerationService;
use App\Service\CommentReplyNotificationService;
use App\Service\CommentSpamGuard;
use App\Service\CommentTargetUrlGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CommentController extends AbstractController
{
    public function __construct(
        private readonly ActionRateLimiter $actionRateLimiter,
        private readonly TranslatorInterface $translator,
        private readonly CommentableContentResolver $contentResolver,
        private readonly CommentTargetUrlGenerator $urlGenerator,
    ) {
    }

    #[Route('/comments/{type}/{slug}', name: 'app_comment_create', requirements: ['type' => 'article|place|hike|city-visit'], methods: ['POST'])]
    #[Route('/articles/{slug}/comments', name: 'app_article_comment_create', defaults: ['type' => 'article'], methods: ['POST'])]
    #[Route('/places/{slug}/comments', name: 'app_place_comment_create', defaults: ['type' => 'place'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(
        string $type,
        string $slug,
        Request $request,
        CommentManager $commentManager,
    ): RedirectResponse {
        $commentableType = CommentableType::tryFrom($type);
        $content = $commentableType instanceof CommentableType
            ? $this->contentResolver->resolvePublic($commentableType, $slug)
            : null;

        if ($content === null) {
            throw $this->createNotFoundException('Contenu commentable introuvable.');
        }

        $author = $this->getAuthenticatedUser();
        if ($this->isBannedCommenter($author)) {
            $this->addFlash('warning', $this->translator->trans('security.account.suspended', domain: 'security'));

            return $this->redirect($this->urlGenerator->forContent($content));
        }

        if (!$this->canUseCommentActions($author)) {
            $this->addFlash('warning', 'Votre email doit être confirmé pour publier un commentaire.');

            return $this->redirect($this->urlGenerator->forContent($content));
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirect($this->urlGenerator->forContent($content));
        }

        $comment = $commentManager->createForContent(
            $content,
            $author,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        $form = $this->createForm(CommentType::class, $comment, [
            'action' => $this->urlGenerator->createAction($content),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            $this->addFlash('error', 'Formulaire non soumis.');

            return $this->redirect($this->urlGenerator->forContent($content, 'comment-form'));
        }

        if ($form->isValid()) {
            if (($spamMessage = $commentManager->publish($comment)) !== null) {
                $this->addFlash('error', $spamMessage);

                return $this->redirect($this->urlGenerator->forContent($content, 'comment-form'));
            }

            if ($comment->getStatus() !== CommentStatus::Approved) {
                $this->addFlash('warning', 'Votre commentaire a été bloqué par l’anti-spam.');

                return $this->redirect($this->urlGenerator->forContent($content, 'comment-form'));
            }

            $this->addFlash('success', 'Votre commentaire a été publié.');

            return $this->redirect($this->urlGenerator->forContent($content, $this->commentFragment($comment)));
        }

        $this->addCommentFormErrorFlashes($form);

        return $this->redirect($this->urlGenerator->forContent($content, 'comment-form'));
    }

    #[Route('/comments/{id}/reply', name: 'app_comment_reply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reply(
        Comment $parent,
        Request $request,
        CommentManager $commentManager,
        ValidatorInterface $validator,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('reply-comment-'.$parent->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($parent);

        if (trim($request->request->getString('website')) !== '') {
            $this->addFlash('error', 'Votre réponse n’a pas pu être envoyée.');

            return $this->redirectToCommentTarget($parent);
        }

        if ($parent->getParent() !== null) {
            $this->addFlash('warning', 'Les réponses sont limitées à un seul niveau.');

            return $this->redirectToCommentTarget($parent);
        }

        if ($parent->getStatus() !== CommentStatus::Approved) {
            $this->addFlash('warning', 'Vous ne pouvez répondre qu’à un commentaire publié.');

            return $this->redirectToCommentTarget($parent);
        }

        $author = $this->getAuthenticatedUser();
        if ($this->isBannedCommenter($author)) {
            $this->addFlash('warning', $this->translator->trans('security.account.suspended', domain: 'security'));

            return $this->redirectToCommentTarget($parent);
        }

        if (!$this->canUseCommentActions($author)) {
            $this->addFlash('warning', 'Votre email doit être confirmé pour répondre.');

            return $this->redirectToCommentTarget($parent);
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirectToCommentTarget($parent);
        }

        $reply = $commentManager->createReply(
            $parent,
            $author,
            $request->request->getString('content'),
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        $violations = $validator->validate($reply);
        if (count($violations) > 0) {
            $message = 'Votre réponse n’a pas pu être envoyée.';
            foreach ($violations as $violation) {
                $message = (string) $violation->getMessage();
                break;
            }
            $this->addFlash('error', $message);

            return $this->redirectToCommentTarget($parent);
        }

        if (($spamMessage = $commentManager->publish($reply)) !== null) {
            $this->addFlash('error', $spamMessage);

            return $this->redirectToCommentTarget($parent);
        }

        if ($reply->getStatus() !== CommentStatus::Approved) {
            $this->addFlash('warning', 'Votre réponse a été bloquée par l’anti-spam.');

            return $this->redirectToCommentTarget($parent);
        }

        $this->addFlash('success', 'Votre réponse a été publiée.');
        return $this->redirectToCommentTarget($reply);
    }

    #[Route('/comments/{id}/edit', name: 'app_comment_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        Comment $comment,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
        CommentReplyNotificationService $notificationService,
        CommentSpamGuard $spamGuard,
        ValidatorInterface $validator,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment);

        if (!$this->isCsrfTokenValid('edit-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($comment);

        $previousStatus = $comment->getStatus();
        $previousContent = $comment->getContent();
        $comment->setContent(trim($request->request->getString('content')));

        $violations = $validator->validate($comment);
        if (count($violations) > 0) {
            $comment->setContent((string) $previousContent);
            $message = 'Votre commentaire n’a pas pu être modifié.';
            foreach ($violations as $violation) {
                $message = (string) $violation->getMessage();
                break;
            }
            $this->addFlash('error', $message);

            return $this->redirectToCommentTarget($comment);
        }

        if (($spamMessage = $spamGuard->validate($comment, $comment)) !== null) {
            $comment->setContent((string) $previousContent);
            $this->addFlash('error', $spamMessage);

            return $this->redirectToCommentTarget($comment);
        }

        $comment->setEditedAt(new \DateTimeImmutable());
        $moderationService->moderateEdited(
            $comment,
            $this->getAuthenticatedUser(),
            $this->isGranted('ROLE_ADMIN'),
            $previousStatus,
        );
        if ($comment->getStatus() === CommentStatus::Approved) {
            $notificationService->createForApprovedComment($comment);
        }

        $entityManager->flush();
        $this->addFlash(
            $comment->getStatus() === CommentStatus::Approved ? 'success' : 'warning',
            $comment->getStatus() === CommentStatus::Approved
                ? 'Votre commentaire a été modifié.'
                : 'Votre commentaire a été masqué par l’anti-spam.',
        );

        return $this->redirectToCommentTarget($comment);
    }

    #[Route('/comments/{id}/delete', name: 'app_comment_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        Comment $comment,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentDeletionService $deletionService,
    ): RedirectResponse
    {
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);

        if (!$this->isCsrfTokenValid('delete-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($comment);

        $redirect = $this->redirectAfterPhysicalDelete($comment);
        $deletionService->deletePhysically($comment);
        $entityManager->flush();

        $this->addFlash('success', 'Votre commentaire a été supprimé.');

        return $redirect;
    }

    #[Route('/comments/{id}/like', name: 'app_comment_like_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggleLike(
        Comment $comment,
        Request $request,
        CommentLikeRepository $likeRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('like-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($comment);

        if ($comment->getStatus() !== CommentStatus::Approved) {
            $this->addFlash('warning', 'Vous ne pouvez aimer qu’un commentaire publié.');

            return $this->redirectToCommentTarget($comment);
        }

        $user = $this->getAuthenticatedUser();
        $existingLike = $likeRepository->findOneByCommentAndUser($comment, $user);
        if ($existingLike instanceof CommentLike) {
            $entityManager->remove($existingLike);
            $entityManager->flush();

            return $this->redirectToCommentTarget($comment);
        }

        $like = (new CommentLike())
            ->setComment($comment)
            ->setUser($user);

        try {
            $entityManager->persist($like);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $entityManager->detach($like);
        }

        return $this->redirectToCommentTarget($comment);
    }

    #[Route('/comments/{id}/admin-heart', name: 'app_comment_admin_heart_toggle', methods: ['POST'])]
    #[IsGranted(AdminAccessVoter::ACCESS)]
    public function toggleAdminHeart(Comment $comment, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin-heart-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($comment);

        if ($comment->getStatus() !== CommentStatus::Approved) {
            $this->addFlash('warning', 'Vous ne pouvez mettre un cœur qu’à un commentaire publié.');

            return $this->redirectToCommentTarget($comment);
        }

        $comment->toggleAdminHeart($this->getAuthenticatedUser());
        $entityManager->flush();

        return $this->redirectToCommentTarget($comment);
    }

    #[Route('/comments/{id}/pin', name: 'app_comment_pin_toggle', methods: ['POST'])]
    #[IsGranted(AdminAccessVoter::ACCESS)]
    public function togglePin(Comment $comment, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('pin-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($comment);

        if ($comment->getParent() !== null) {
            $this->addFlash('warning', 'Seuls les commentaires principaux peuvent être épinglés.');

            return $this->redirectToCommentTarget($comment);
        }

        if ($comment->getStatus() !== CommentStatus::Approved) {
            $this->addFlash('warning', 'Vous ne pouvez épingler qu’un commentaire publié.');

            return $this->redirectToCommentTarget($comment);
        }

        $comment->togglePinned($this->getAuthenticatedUser());
        $entityManager->flush();

        return $this->redirectToCommentTarget($comment);
    }

    #[Route('/comments/{id}/report', name: 'app_comment_report', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function report(
        Comment $comment,
        Request $request,
        CommentReportRepository $reportRepository,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('report-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->assertPublicCommentTarget($comment);

        $reporter = $this->getAuthenticatedUser();
        if ($reportRepository->findOneByCommentAndReporter($comment, $reporter) !== null) {
            $this->addFlash('warning', 'Vous avez déjà signalé ce commentaire.');

            return $this->redirectToCommentTarget($comment);
        }

        $this->denyAccessUnlessGranted(CommentVoter::REPORT, $comment);

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentReport($request, $reporter))) {
            return $this->redirectToCommentTarget($comment);
        }

        $reason = CommentReportReason::tryFrom((string) $request->request->get('reason')) ?? CommentReportReason::Other;
        $message = trim($request->request->getString('message'));

        $report = (new CommentReport())
            ->setComment($comment)
            ->setReporter($reporter)
            ->setReason($reason)
            ->setMessage($message === '' ? null : mb_substr($message, 0, 2000))
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'));

        $comment->incrementReportedCount();
        $moderationService->hideForPendingReportReview($comment);

        $entityManager->persist($report);
        $entityManager->flush();

        $this->addFlash('success', 'Merci, le commentaire a été signalé à la modération.');

        return $this->redirectToCommentTarget($comment);
    }

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function redirectToCommentTarget(Comment $comment): RedirectResponse
    {
        $content = $this->assertPublicCommentTarget($comment);

        return $this->redirect($this->urlGenerator->forContent($content, $this->commentFragment($comment)));
    }

    private function redirectAfterPhysicalDelete(Comment $comment): RedirectResponse
    {
        $content = $this->assertPublicCommentTarget($comment);
        $fragment = 'comments';
        $parent = $comment->getParent();
        if ($parent instanceof Comment && $parent->getId() !== null) {
            $fragment = 'comment-'.$parent->getId();
        }

        return $this->redirect($this->urlGenerator->forContent($content, $fragment));
    }

    private function assertPublicCommentTarget(Comment $comment): CommentableContentInterface
    {
        $thread = $comment->getThread();
        $content = $thread === null ? null : $this->contentResolver->resolvePublicThread($thread);

        if (!$content instanceof CommentableContentInterface) {
            throw $this->createNotFoundException('Le contenu associé à ce commentaire n’est pas public.');
        }

        return $content;
    }

    private function commentFragment(Comment $comment): string
    {
        if ($comment->getId() === null) {
            return 'comments';
        }

        if ($comment->getStatus() === CommentStatus::Approved) {
            return 'comment-'.$comment->getId();
        }

        $parent = $comment->getParent();
        if ($parent instanceof Comment && $parent->getId() !== null) {
            return 'comment-'.$parent->getId();
        }

        return 'comments';
    }

    private function isBannedCommenter(User $user): bool
    {
        return $user->isBanned() && !$user->isAdmin();
    }

    private function canUseCommentActions(User $user): bool
    {
        return $user->isVerified();
    }

    private function acceptRateLimit(RateLimit $limit): bool
    {
        if ($limit->isAccepted()) {
            return true;
        }

        $this->addFlash('warning', sprintf(
            'Trop de tentatives. Réessayez à partir de %s.',
            $limit->getRetryAfter()->format('H:i'),
        ));

        return false;
    }

    /** @param FormInterface<Comment> $form */
    private function addCommentFormErrorFlashes(FormInterface $form): void
    {
        $messages = [];

        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }

        $messages = array_values(array_unique(array_filter($messages)));
        if ($messages === []) {
            $this->addFlash('error', 'Votre commentaire n’a pas pu être envoyé.');

            return;
        }

        foreach (array_slice($messages, 0, 3) as $message) {
            $this->addFlash('error', $message);
        }
    }
}
