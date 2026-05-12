<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ActionRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $commentCreateLimiter,
        private readonly RateLimiterFactoryInterface $commentReportLimiter,
        private readonly RateLimiterFactoryInterface $adminUploadLimiter,
    ) {
    }

    public function consumeCommentCreate(Request $request, User $user): RateLimit
    {
        return $this->consume($this->commentCreateLimiter, $request, $user, 'comment_create');
    }

    public function consumeCommentReport(Request $request, User $user): RateLimit
    {
        return $this->consume($this->commentReportLimiter, $request, $user, 'comment_report');
    }

    public function consumeAdminUpload(Request $request, ?User $user): RateLimit
    {
        return $this->consume($this->adminUploadLimiter, $request, $user, 'admin_upload');
    }

    private function consume(RateLimiterFactoryInterface $limiter, Request $request, ?User $user, string $scope): RateLimit
    {
        $userKey = $user instanceof User
            ? sprintf('user:%s', $user->getId() ?? sha1($user->getUserIdentifier()))
            : 'anonymous';
        $ipKey = $request->getClientIp() ?? 'unknown-ip';
        $routeKey = $request->attributes->get('_route') ?? $request->getPathInfo();

        return $limiter
            ->create(hash('sha256', sprintf('%s|%s|%s|%s', $scope, $userKey, $ipKey, $routeKey)))
            ->consume();
    }
}
