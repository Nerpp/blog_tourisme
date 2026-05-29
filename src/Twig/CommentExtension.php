<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\CommentReplyNotificationRepository;
use App\Service\CommentMentionService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CommentExtension extends AbstractExtension
{
    public function __construct(
        private readonly CommentMentionService $mentionService,
        private readonly CommentReplyNotificationRepository $notificationRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('comment_content_html', [$this, 'contentHtml'], ['is_safe' => ['html']]),
            new TwigFunction('comment_unread_notification_count', [$this, 'unreadNotificationCount']),
        ];
    }

    public function contentHtml(?string $content): string
    {
        return $this->mentionService->renderHtml((string) $content);
    }

    public function unreadNotificationCount(mixed $user): int
    {
        if (!$user instanceof User) {
            return 0;
        }

        return $this->notificationRepository->countUnreadForRecipient($user);
    }
}
