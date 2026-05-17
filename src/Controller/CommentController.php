<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportReason;
use App\Form\CommentType;
use App\Repository\ArticleRepository;
use App\Repository\CommentReportRepository;
use App\Repository\PlaceRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\CommentVoter;
use App\Service\CommentModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    ): RedirectResponse {
        $article = $articleRepository->findPublishedBySlug($slug);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        $author = $this->getAuthenticatedUser();
        if ($this->isBannedCommenter($author)) {
            $this->addFlash('warning', 'Votre compte est suspendu. Vous ne pouvez plus publier de commentaire.');

            return $this->redirectToRoute('app_article_show', ['slug' => $article->getSlug()]);
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirectToRoute('app_article_show', ['slug' => $article->getSlug()]);
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
            $entityManager->flush();

            $this->addFlash('success', 'Votre commentaire a ete envoye.');
        } else {
            $this->addFlash('error', 'Votre commentaire n a pas pu etre envoye.');
        }

        return $this->redirectToRoute('app_article_show', ['slug' => $article->getSlug()]);
    }

    #[Route('/places/{slug}/comments', name: 'app_place_comment_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted(AdminAccessVoter::ACCESS)]
    public function createForPlace(
        string $slug,
        Request $request,
        PlaceRepository $placeRepository,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
    ): RedirectResponse {
        $place = $placeRepository->findPublishedBySlug($slug);
        if ($place === null) {
            throw $this->createNotFoundException('Lieu introuvable.');
        }

        $author = $this->getAuthenticatedUser();
        if ($this->isBannedCommenter($author)) {
            $this->addFlash('warning', 'Votre compte est suspendu. Vous ne pouvez plus publier de commentaire.');

            return $this->redirectToRoute('app_place_show', ['slug' => $place->getSlug()]);
        }

        if (!$this->acceptRateLimit($this->actionRateLimiter->consumeCommentCreate($request, $author))) {
            return $this->redirectToRoute('app_place_show', ['slug' => $place->getSlug()]);
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
            $entityManager->flush();

            $this->addFlash('success', 'Votre commentaire a ete envoye.');
        } else {
            $this->addFlash('error', 'Votre commentaire n a pas pu etre envoye.');
        }

        return $this->redirectToRoute('app_place_show', ['slug' => $place->getSlug()]);
    }

    #[Route('/comments/{id}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        Comment $comment,
        Request $request,
        EntityManagerInterface $entityManager,
        CommentModerationService $moderationService,
    ): Response {
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment);

        $previousStatus = $comment->getStatus();
        $form = $this->createForm(CommentType::class, $comment, [
            'submit_label' => 'Modifier',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setEditedAt(new \DateTimeImmutable());
            $moderationService->moderateEdited(
                $comment,
                $this->getAuthenticatedUser(),
                $this->isGranted('ROLE_ADMIN'),
                $previousStatus,
            );

            $entityManager->flush();
            $this->addFlash('success', 'Votre commentaire a ete modifie.');

            return $this->redirectToCommentTarget($comment);
        }

        return $this->render('comment/edit.html.twig', [
            'comment' => $comment,
            'comment_form' => $form->createView(),
        ]);
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

        $this->addFlash('success', 'Votre commentaire a ete supprime.');

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
            $this->addFlash('warning', 'Vous avez deja signale ce commentaire.');

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

        $this->addFlash('success', 'Merci, votre signalement a ete enregistre.');

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
        if ($comment->getArticle() !== null) {
            return $this->redirectToRoute('app_article_show', ['slug' => $comment->getArticle()->getSlug()]);
        }

        if ($comment->getPlace() !== null) {
            return $this->redirectToRoute('app_place_show', ['slug' => $comment->getPlace()->getSlug()]);
        }

        return $this->redirectToRoute('app_home');
    }

    private function isBannedCommenter(User $user): bool
    {
        return $user->isBanned() && !in_array('ROLE_ADMIN', $user->getRoles(), true);
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
