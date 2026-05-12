<?php

namespace App\Service;

use App\Entity\ModerationActionLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ModerationActionLogger
{
    private ?bool $tableExists = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed>|null $metadata */
    public function log(
        string $action,
        ?User $actor,
        string $targetType,
        ?int $targetId,
        ?string $summary = null,
        ?Request $request = null,
        ?User $targetUser = null,
        ?array $metadata = null,
    ): void {
        if (!$this->moderationLogTableExists()) {
            return;
        }

        $log = (new ModerationActionLog())
            ->setAction($action)
            ->setActor($actor)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setTargetUser($targetUser)
            ->setSummary($summary)
            ->setMetadata($metadata)
            ->setIpAddress($request?->getClientIp())
            ->setUserAgent($request?->headers->get('User-Agent'));

        $this->entityManager->persist($log);
    }

    private function moderationLogTableExists(): bool
    {
        if ($this->tableExists === null) {
            $this->tableExists = $this->entityManager
                ->getConnection()
                ->createSchemaManager()
                ->tablesExist(['moderation_action_log']);
        }

        return $this->tableExists;
    }
}
