<?php

namespace App\Controller\Internal;

use App\Service\Instagram\InstagramWebCronProcessorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

final readonly class InstagramWebCronController
{
    public const string BASIC_USERNAME = 'estela-instagram-cron';
    public const string LOCK_RESOURCE = 'estela.instagram.webcron';

    private const int LOCK_TTL_SECONDS = 720;
    private const int MINIMUM_SECRET_LENGTH = 32;

    public function __construct(
        private InstagramWebCronProcessorInterface $processor,
        #[Target('instagram_webcron')]
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private ?string $instagramCronSecret,
    ) {
    }

    #[Route(
        '/_internal/cron/instagram',
        name: 'internal_instagram_webcron',
        defaults: [
            '_collect_profile' => false,
            '_stateless' => true,
        ],
    )]
    public function __invoke(Request $request): Response
    {
        if (!in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_POST, Request::METHOD_HEAD], true)) {
            return $this->response(
                ['status' => 'method_not_allowed'],
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => 'GET, HEAD, POST'],
            );
        }

        if (!$request->isSecure()) {
            return $this->response(['status' => 'https_required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->hasValidConfiguration()) {
            $this->logger->error('Le secret du WebCron Instagram est absent ou invalide.');

            return $this->response(['status' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$this->isAuthenticated($request)) {
            $this->logger->warning('Appel WebCron Instagram refusé.', [
                'method' => $request->getMethod(),
            ]);

            return $this->response(
                ['status' => 'unauthorized'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Basic realm="Estela WebCron", charset="UTF-8"'],
            );
        }

        // Symfony maps HEAD to GET routes. Keep this authenticated probe strictly
        // side-effect free so production can validate the endpoint safely.
        if ($request->isMethod(Request::METHOD_HEAD)) {
            return $this->response(null, Response::HTTP_NO_CONTENT);
        }

        $lock = null;
        $lockAcquired = false;

        try {
            $lock = $this->lockFactory->createLock(
                self::LOCK_RESOURCE,
                self::LOCK_TTL_SECONDS,
                true,
            );
            $lockAcquired = $lock->acquire();
            if (!$lockAcquired) {
                $this->logger->info('WebCron Instagram déjà en cours, appel ignoré.');

                return $this->response(['status' => 'busy', 'accepted' => true]);
            }

            $this->processor->run();

            return $this->response(['status' => 'ok', 'accepted' => true]);
        } catch (\Throwable $exception) {
            $this->logger->error('Échec interne du WebCron Instagram.', [
                'exception_class' => $exception::class,
            ]);

            return $this->response(['status' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        } finally {
            if ($lockAcquired && $lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable $exception) {
                    $this->logger->warning('Le verrou WebCron Instagram n’a pas pu être libéré proprement.', [
                        'exception_class' => $exception::class,
                    ]);
                }
            }
        }
    }

    private function hasValidConfiguration(): bool
    {
        return is_string($this->instagramCronSecret)
            && strlen($this->instagramCronSecret) >= self::MINIMUM_SECRET_LENGTH
            && preg_match('/[\x00-\x20\x7F]/', $this->instagramCronSecret) !== 1;
    }

    private function isAuthenticated(Request $request): bool
    {
        $providedUsername = $request->getUser() ?? '';
        $providedSecret = $request->getPassword() ?? '';

        $validUsername = hash_equals(self::BASIC_USERNAME, $providedUsername);
        $validSecret = hash_equals((string) $this->instagramCronSecret, $providedSecret);

        return $validUsername && $validSecret;
    }

    /**
     * @param array{status: string, accepted?: true}|null $payload
     * @param array<string, string>                  $headers
     */
    private function response(
        ?array $payload,
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): Response {
        $response = $payload === null
            ? new Response(null, $status, $headers)
            : new JsonResponse($payload, $status, $headers);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
