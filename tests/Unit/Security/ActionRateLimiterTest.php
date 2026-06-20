<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\ActionRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class ActionRateLimiterTest extends TestCase
{
    public function testCommentCreateUsesStableUserIdClientIpAndRouteKey(): void
    {
        $factory = new CollectingRateLimiterFactory($this->acceptedRateLimit());
        $limiter = $this->limiter(commentCreateFactory: $factory);
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'app_comment_create');
        $user = $this->user('reader@example.test', 42);

        self::assertSame($factory->rateLimit, $limiter->consumeCommentCreate($request, $user));
        self::assertSame([
            hash('sha256', 'comment_create|user:42|203.0.113.10|app_comment_create'),
        ], $factory->keys);
    }

    public function testCommentReportFallsBackToUserIdentifierWhenUserHasNoId(): void
    {
        $factory = new CollectingRateLimiterFactory($this->acceptedRateLimit());
        $limiter = $this->limiter(commentReportFactory: $factory);
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.11']);
        $request->attributes->set('_route', 'app_comment_report');
        $user = $this->user('reader@example.test');

        self::assertSame($factory->rateLimit, $limiter->consumeCommentReport($request, $user));
        self::assertSame([
            hash('sha256', 'comment_report|user:'.sha1('reader@example.test').'|203.0.113.11|app_comment_report'),
        ], $factory->keys);
    }

    public function testAdminUploadSupportsAnonymousUserAndPathFallback(): void
    {
        $factory = new CollectingRateLimiterFactory($this->acceptedRateLimit());
        $limiter = $this->limiter(adminUploadFactory: $factory);
        $request = new Request(server: [
            'REMOTE_ADDR' => '198.51.100.7',
            'REQUEST_URI' => '/admin/media/upload',
        ]);

        self::assertSame($factory->rateLimit, $limiter->consumeAdminUpload($request, null));
        self::assertSame([
            hash('sha256', 'admin_upload|anonymous|198.51.100.7|/admin/media/upload'),
        ], $factory->keys);
    }

    public function testAdminUploadFallsBackToUnknownIpWhenRequestHasNoClientIp(): void
    {
        $factory = new CollectingRateLimiterFactory($this->acceptedRateLimit());
        $limiter = $this->limiter(adminUploadFactory: $factory);
        $request = new Request(server: ['REQUEST_URI' => '/admin/media/upload']);

        self::assertSame($factory->rateLimit, $limiter->consumeAdminUpload($request, null));
        self::assertSame([
            hash('sha256', 'admin_upload|anonymous|unknown-ip|/admin/media/upload'),
        ], $factory->keys);
    }

    private function limiter(
        ?CollectingRateLimiterFactory $commentCreateFactory = null,
        ?CollectingRateLimiterFactory $commentReportFactory = null,
        ?CollectingRateLimiterFactory $adminUploadFactory = null,
    ): ActionRateLimiter {
        $commentCreateFactory ??= new CollectingRateLimiterFactory($this->acceptedRateLimit());
        $commentReportFactory ??= new CollectingRateLimiterFactory($this->acceptedRateLimit());
        $adminUploadFactory ??= new CollectingRateLimiterFactory($this->acceptedRateLimit());

        return new ActionRateLimiter($commentCreateFactory, $commentReportFactory, $adminUploadFactory);
    }

    private function acceptedRateLimit(): RateLimit
    {
        return new RateLimit(9, new \DateTimeImmutable('+1 minute'), true, 10);
    }

    private function user(string $email, ?int $id = null): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('test-password');

        if ($id !== null) {
            $property = new \ReflectionProperty($user, 'id');
            $property->setValue($user, $id);
        }

        return $user;
    }
}

final class CollectingRateLimiterFactory implements RateLimiterFactoryInterface
{
    /** @var list<string|null> */
    public array $keys = [];

    public function __construct(public readonly RateLimit $rateLimit)
    {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $this->keys[] = $key;

        return new FixedRateLimiter($this->rateLimit);
    }
}

final class FixedRateLimiter implements LimiterInterface
{
    public function __construct(private readonly RateLimit $rateLimit)
    {
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        throw new \BadMethodCallException('Reserve is not used by this test double.');
    }

    public function consume(int $tokens = 1): RateLimit
    {
        return $this->rateLimit;
    }

    public function reset(): void
    {
    }
}
