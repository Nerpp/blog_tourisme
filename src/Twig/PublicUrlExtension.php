<?php

namespace App\Twig;

use App\Service\Seo\PublicUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PublicUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly PublicUrlGenerator $publicUrlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_url', $this->publicUrlGenerator->absolute(...)),
            new TwigFunction('public_route_url', $this->publicUrlGenerator->generate(...)),
            new TwigFunction('public_canonical_url', $this->canonicalUrl(...)),
        ];
    }

    public function canonicalUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request !== null
            ? $this->publicUrlGenerator->canonicalForRequest($request)
            : $this->publicUrlGenerator->generate('app_home');
    }
}
