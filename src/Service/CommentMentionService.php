<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CommentMentionService
{
    private const MENTION_PATTERN = '/(?<![\pL\pN_])@([A-Za-z0-9_.-]{2,80})/u';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return list<string> */
    public function extractHandles(string $content): array
    {
        if (preg_match_all(self::MENTION_PATTERN, $content, $matches) !== false) {
            return array_values(array_unique(array_map(
                static fn (string $handle): string => mb_strtolower($handle),
                $matches[1],
            )));
        }

        return [];
    }

    /** @return list<User> */
    public function findMentionedUsers(string $content): array
    {
        return $this->userRepository->findMentionableUsersByHandles($this->extractHandles($content));
    }

    public function renderHtml(string $content): string
    {
        $escapedContent = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $usersByHandle = [];

        foreach ($this->findMentionedUsers($content) as $user) {
            if ($user->getId() === null) {
                continue;
            }

            $usersByHandle[$user->getMentionHandle()] = $user;
        }

        $linkedContent = preg_replace_callback(
            self::MENTION_PATTERN,
            function (array $matches) use ($usersByHandle): string {
                $handle = mb_strtolower((string) $matches[1]);
                $user = $usersByHandle[$handle] ?? null;
                if (!$user instanceof User || $user->getId() === null) {
                    return (string) $matches[0];
                }

                return sprintf(
                    '<a class="comment-mention" href="%s">@%s</a>',
                    htmlspecialchars($this->urlGenerator->generate('app_public_profile', ['id' => $user->getId()]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            },
            $escapedContent,
        ) ?? $escapedContent;

        return nl2br($linkedContent, false);
    }
}
