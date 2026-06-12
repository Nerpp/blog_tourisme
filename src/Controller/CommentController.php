<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportReason;
use App\Enum\CommentStatus;
use App\Form\CommentType;
use App\Repository\ArticleRepository;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentReportRepository;
use App\Repository\PlaceRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\CommentVoter;
use App\Service\CommentDeletionService;
use App\Service\CommentModerationService;
use App\Service\CommentReplyNotificationService;
use App\Service\CommentSpamGuard;
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

final class CommentController extends AbstractController
{
    public function __construct(
        private readonly ActionRateLimiter $actionRateLimiter,
    ) {
    }

    #[Route('/articles/{slug}/comments', name: 'app_article_comment_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createForArticle(
        string $slug,
        Request $request,
        ArticleRepository $articleRepository,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
        CommentReplyNotificationService $notificationService,
        CommentSpamGuard $spamGuard,
    ): RedirectResponse {
        $article = $articleRepository->findPublishedBySlug($slug);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        $author = $this->getAuthenticatedUser();
        if ($this->isBannedCommenter($author)) {
            $this->addFlash('warning', 'Votre compte est suspendu. Vous ne pouvez plus publier de commentaire.');

            return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comments');
        }

        if (!$this->canUseCommentActions($author)) {
            $this->addFlash('warning', 'Votre email doit être confirmé pour publier un commentaire.');

            return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comments');
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comments');
        }

        $comment = (new Comment())
            ->setArticle($article)
            ->setAuthor($author)
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'));

        $form = $this->createForm(CommentType::class, $comment, [
            'action' => $this->generateUrl('app_article_comment_create', ['slug' => $article->getSlug()]),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            $this->addFlash('error', 'Formulaire non soumis.');

            return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comment-form');
        }

        if ($form->isValid()) {
            if (($spamMessage = $spamGuard->validate($comment)) !== null) {
                $this->addFlash('error', $spamMessage);

                return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comment-form');
            }

            $moderationService->moderateNew($comment);
            $entityManager->persist($comment);
            if ($comment->getStatus() === CommentStatus::Approved) {
                $notificationService->createForApprovedComment($comment);
            }
            $entityManager->flush();

            if ($comment->getStatus() !== CommentStatus::Approved) {
                $this->addFlash('warning', 'Votre commentaire a été bloqué par l’anti-spam.');

                return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comment-form');
            }

            $this->addFlash('success', 'Votre commentaire a été publié.');
            return $this->redirectToCommentTarget($comment);
        }

        $this->addCommentFormErrorFlashes($form);

        return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comment-form');
    }

    #[Route('/places/{slug}/comments', name: 'app_place_comment_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createForPlace(
        string $slug,
        Request $request,
        PlaceRepository $placeRepository,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
        CommentReplyNotificationService $notificationService,
        CommentSpamGuard $spamGuard,
    ): RedirectResponse {
        $place = $placeRepository->findPublishedBySlug($slug);
        if ($place === null) {
            throw $this->createNotFoundException('Lieu introuvable.');
        }

        $author = $this->getAuthenticatedUser();
        if ($this->isBannedCommenter($author)) {
            $this->addFlash('warning', 'Votre compte est suspendu. Vous ne pouvez plus publier de commentaire.');

            return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comments');
        }

        if (!$this->canUseCommentActions($author)) {
            $this->addFlash('warning', 'Votre email doit être confirmé pour publier un commentaire.');

            return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comments');
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comments');
        }

        $comment = (new Comment())
            ->setPlace($place)
            ->setAuthor($author)
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'));

        $form = $this->createForm(CommentType::class, $comment, [
            'action' => $this->generateUrl('app_place_comment_create', ['slug' => $place->getSlug()]),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            $this->addFlash('error', 'Formulaire non soumis.');

            return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comment-form');
        }

        if ($form->isValid()) {
            if (($spamMessage = $spamGuard->validate($comment)) !== null) {
                $this->addFlash('error', $spamMessage);

                return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comment-form');
            }

            $moderationService->moderateNew($comment);
            $entityManager->persist($comment);
            if ($comment->getStatus() === CommentStatus::Approved) {
                $notificationService->createForApprovedComment($comment);
            }
            $entityManager->flush();

            if ($comment->getStatus() !== CommentStatus::Approved) {
                $this->addFlash('warning', 'Votre commentaire a été bloqué par l’anti-spam.');

                return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comment-form');
            }

            $this->addFlash('success', 'Votre commentaire a été publié.');
            return $this->redirectToCommentTarget($comment);
        }

        $this->addCommentFormErrorFlashes($form);

        return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comment-form');
    }

    #[Route('/comments/{id}/reply', name: 'app_comment_reply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reply(
        Comment $parent,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
        CommentReplyNotificationService $notificationService,
        CommentSpamGuard $spamGuard,
        ValidatorInterface $validator,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('reply-comment-'.$parent->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

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
            $this->addFlash('warning', 'Votre compte est suspendu. Vous ne pouvez plus publier de commentaire.');

            return $this->redirectToCommentTarget($parent);
        }

        if (!$this->canUseCommentActions($author)) {
            $this->addFlash('warning', 'Votre email doit être confirmé pour répondre.');

            return $this->redirectToCommentTarget($parent);
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirectToCommentTarget($parent);
        }

        $reply = (new Comment())
            ->setAuthor($author)
            ->setParent($parent)
            ->setArticle($parent->getArticle())
            ->setPlace($parent->getPlace())
            ->setContent(trim($request->request->getString('content')))
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'));

        $violations = $validator->validate($reply);
        if (count($violations) > 0) {
            $this->addFlash('error', $violations[0]->getMessage());

            return $this->redirectToCommentTarget($parent);
        }

        if (($spamMessage = $spamGuard->validate($reply)) !== null) {
            $this->addFlash('error', $spamMessage);

            return $this->redirectToCommentTarget($parent);
        }

        $moderationService->moderateNew($reply);
        $entityManager->persist($reply);
        if ($reply->getStatus() === CommentStatus::Approved) {
            $notificationService->createForApprovedComment($reply);
        }
        $entityManager->flush();

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

        $previousStatus = $comment->getStatus();
        $previousContent = $comment->getContent();
        $comment->setContent(trim($request->request->getString('content')));

        $violations = $validator->validate($comment);
        if (count($violations) > 0) {
            $comment->setContent((string) $previousContent);
            $this->addFlash('error', $violations[0]->getMessage());

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
        $fragment = $this->commentFragment($comment);

        if ($comment->getArticle() !== null) {
            return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $comment->getArticle()->getSlug()], $fragment);
        }

        if ($comment->getPlace() !== null) {
            return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $comment->getPlace()->getSlug()], $fragment);
        }

        return $this->redirectToRoute('app_home');
    }

    private function redirectAfterPhysicalDelete(Comment $comment): RedirectResponse
    {
        $fragment = 'comments';
        $parent = $comment->getParent();
        if ($parent instanceof Comment && $parent->getId() !== null) {
            $fragment = 'comment-'.$parent->getId();
        }

        if ($comment->getArticle() !== null) {
            return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $comment->getArticle()->getSlug()], $fragment);
        }

        if ($comment->getPlace() !== null) {
            return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $comment->getPlace()->getSlug()], $fragment);
        }

        return $this->redirectToRoute('app_home');
    }

    /** @param array<string, mixed> $parameters */
    private function redirectToRouteWithFragment(string $route, array $parameters, string $fragment): RedirectResponse
    {
        return $this->redirect($this->generateUrl($route, $parameters).'#'.$fragment);
    }

    private function commentFragment(Comment $comment): string
    {
        if ($comment->getId() === null) {
            return 'comments';
        }

        if ($comment->getStatus() === CommentStatus::Approved) {
            return 'comment-'.$comment->getId();
        }

        $user = $this->getUser();
        if (
            $user instanceof User
            && $comment->getAuthor()?->getId() === $user->getId()
            && in_array($comment->getStatus(), [CommentStatus::Pending, CommentStatus::Rejected], true)
        ) {
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
        return $user->isBanned() && !in_array('ROLE_ADMIN', $user->getRoles(), true);
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

    /** @param FormInterface<mixed> $form */
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
