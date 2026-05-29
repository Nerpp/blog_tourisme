<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportReason;
use App\Enum\CommentStatus;
use App\Form\CommentType;
use App\Repository\ArticleRepository;
use App\Repository\CommentReportRepository;
use App\Repository\PlaceRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\CommentVoter;
use App\Service\CommentModerationService;
use App\Service\CommentReplyNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $moderationService->moderateNew($comment);
            $entityManager->persist($comment);
            $notificationService->createForApprovedComment($comment);
            $entityManager->flush();

            $this->addFlash('success', $comment->getStatus() === CommentStatus::Approved
                ? 'Votre commentaire a été publié.'
                : 'Votre commentaire a été envoyé et sera visible après modération.');

            return $this->redirectToCommentTarget($comment);
        } else {
            $this->addFlash('error', 'Votre commentaire n’a pas pu être envoyé.');
        }

        return $this->redirectToRouteWithFragment('app_article_show', ['slug' => $article->getSlug()], 'comments');
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

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $moderationService->moderateNew($comment);
            $entityManager->persist($comment);
            $notificationService->createForApprovedComment($comment);
            $entityManager->flush();

            $this->addFlash('success', $comment->getStatus() === CommentStatus::Approved
                ? 'Votre commentaire a été publié.'
                : 'Votre commentaire a été envoyé et sera visible après modération.');

            return $this->redirectToCommentTarget($comment);
        } else {
            $this->addFlash('error', 'Votre commentaire n’a pas pu être envoyé.');
        }

        return $this->redirectToRouteWithFragment('app_place_show', ['slug' => $place->getSlug()], 'comments');
    }

    #[Route('/comments/{id}/reply', name: 'app_comment_reply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reply(
        Comment $parent,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
        CommentReplyNotificationService $notificationService,
        ValidatorInterface $validator,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('reply-comment-'.$parent->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
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

        $moderationService->moderateNew($reply);
        $entityManager->persist($reply);
        $notificationService->createForApprovedComment($reply);
        $entityManager->flush();

        $this->addFlash('success', $reply->getStatus() === CommentStatus::Approved
            ? 'Votre réponse a été publiée.'
            : 'Votre réponse a été envoyée et sera visible après modération.');

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

        $comment->setEditedAt(new \DateTimeImmutable());
        $moderationService->moderateEdited(
            $comment,
            $this->getAuthenticatedUser(),
            $this->isGranted('ROLE_ADMIN'),
            $previousStatus,
        );
        $notificationService->createForApprovedComment($comment);

        $entityManager->flush();
        $this->addFlash('success', $comment->getStatus() === CommentStatus::Approved
            ? 'Votre commentaire a été modifié.'
            : 'Votre modification a été envoyée en modération.');

        return $this->redirectToCommentTarget($comment);
    }

    #[Route('/comments/{id}/delete', name: 'app_comment_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Comment $comment, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);

        if (!$this->isCsrfTokenValid('delete-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $comment->markDeleted();
        $entityManager->flush();

        $this->addFlash('success', 'Votre commentaire a été supprimé.');

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
        $this->denyAccessUnlessGranted(CommentVoter::REPORT, $comment);

        if (!$this->isCsrfTokenValid('report-comment-'.$comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $reporter = $this->getAuthenticatedUser();
        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentReport($request, $reporter))) {
            return $this->redirectToCommentTarget($comment);
        }

        if ($reportRepository->findOneByCommentAndReporter($comment, $reporter) !== null) {
            $this->addFlash('warning', 'Vous avez déjà signalé ce commentaire.');

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
        $moderationService->applyReportThreshold($comment);

        $entityManager->persist($report);
        $entityManager->flush();

        $this->addFlash('success', 'Merci, votre signalement a été enregistré.');

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

        if (in_array($comment->getStatus(), [CommentStatus::Approved, CommentStatus::Deleted], true)) {
            return 'comment-'.$comment->getId();
        }

        $user = $this->getUser();
        if ($user instanceof User && $comment->getAuthor()?->getId() === $user->getId()) {
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
}
