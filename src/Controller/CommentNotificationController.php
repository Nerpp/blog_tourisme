<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\CommentReplyNotification;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Repository\CommentReplyNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CommentNotificationController extends AbstractController
{
    #[Route('/notifications/commentaires', name: 'app_comment_notifications', methods: ['GET'])]
    public function index(
        CommentReplyNotificationRepository $notificationRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getAuthenticatedUser();
        $notifications = $notificationRepository->findRecentForRecipient($user);
        $unreadNotificationIds = [];

        foreach ($notifications as $notification) {
            if (!$notification->isRead() && $notification->getId() !== null) {
                $unreadNotificationIds[] = $notification->getId();
                $notification->markRead();
            }
        }

        if ($unreadNotificationIds !== []) {
            $entityManager->flush();
        }

        return $this->render('comment/notifications.html.twig', [
            'notifications' => $notifications,
            'unread_notification_ids' => $unreadNotificationIds,
        ]);
    }

    #[Route('/notifications/commentaires/{id}', name: 'app_comment_notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(CommentReplyNotification $notification, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getAuthenticatedUser();
        if ($notification->getRecipient()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $notification->markRead();
        $entityManager->flush();

        return $this->redirect($this->commentUrl($notification->getComment()));
    }

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function commentUrl(?Comment $comment): string
    {
        if (!$comment instanceof Comment) {
            return $this->generateUrl('app_home');
        }

        $fragment = in_array($comment->getStatus(), [CommentStatus::Approved, CommentStatus::Deleted], true)
            && $comment->getId() !== null
            ? 'comment-'.$comment->getId()
            : 'comments';

        if ($comment->getArticle() !== null) {
            return $this->generateUrl('app_article_show', ['slug' => $comment->getArticle()->getSlug()]).'#'.$fragment;
        }

        if ($comment->getPlace() !== null) {
            return $this->generateUrl('app_place_show', ['slug' => $comment->getPlace()->getSlug()]).'#'.$fragment;
        }

        return $this->generateUrl('app_home');
    }
}
