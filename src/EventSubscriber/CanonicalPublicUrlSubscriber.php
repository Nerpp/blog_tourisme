<?php

namespace App\EventSubscriber;

use App\Service\Seo\PublicUrlGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CanonicalPublicUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PublicUrlGenerator $publicUrlGenerator,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['redirectCanonicalVariants', 64],
        ];
    }

    public function redirectCanonicalVariants(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $target = $this->publicUrlGenerator->canonicalRedirectFor($event->getRequest());
        if ($target !== null) {
            $event->setResponse(new RedirectResponse($target, RedirectResponse::HTTP_PERMANENTLY_REDIRECT));
        }
    }
}
