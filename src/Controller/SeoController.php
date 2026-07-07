<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SeoController extends AbstractController
{
    #[Route('/plan-du-site', name: 'app_sitemap_html', methods: ['GET'])]
    public function htmlSitemap(): Response
    {
        return $this->render('seo/sitemap.html.twig');
    }

    #[Route('/sitemap.xml', name: 'app_sitemap_xml', methods: ['GET'])]
    public function xmlSitemap(
        DestinationRepository $destinationRepository,
        ArticleRepository $articleRepository,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
        PlaceRepository $placeRepository,
    ): Response {
        /** @var array<string, array{loc: string, lastmod: string|null}> $entries */
        $entries = [];

        foreach ([
            'app_home',
            'app_destination_index',
            'app_hike_index',
            'app_city_visit_index',
            'app_article_index',
            'app_place_index',
            'app_sitemap_html',
        ] as $route) {
            $this->addSitemapEntry($entries, $route);
        }

        foreach ($destinationRepository->findForSitemap() as $destination) {
            $this->addSitemapEntry($entries, 'app_destination_show', [
                'slug' => (string) $destination->getSlug(),
            ], $destination->getUpdatedAt());
        }

        foreach ($articleRepository->findPublishedForSitemap() as $article) {
            $this->addSitemapEntry($entries, 'app_article_show', [
                'slug' => (string) $article->getSlug(),
            ], $article->getUpdatedAt() ?? $article->getPublishedAt());
        }

        foreach ($hikeDraftRepository->findPublicForSitemap() as $hike) {
            $this->addSitemapEntry($entries, 'app_hike_show', [
                'slug' => (string) $hike->getSlug(),
            ], $hike->getUpdatedAt() ?? $hike->getFinishedAt());
        }

        foreach ($cityVisitDraftRepository->findPublicForSitemap() as $cityVisit) {
            $this->addSitemapEntry($entries, 'app_city_visit_show', [
                'slug' => (string) $cityVisit->getSlug(),
            ], $cityVisit->getUpdatedAt() ?? $cityVisit->getFinishedAt());
        }

        foreach ($placeRepository->findPublishedForSitemap() as $place) {
            $this->addSitemapEntry($entries, 'app_place_show', [
                'slug' => (string) $place->getSlug(),
            ], $place->getUpdatedAt() ?? $place->getPublishedAt());
        }

        $response = $this->render('seo/sitemap.xml.twig', [
            'entries' => array_values($entries),
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            '',
            'Disallow: /admin/',
            '',
            'Sitemap: '.$this->generateUrl('app_sitemap_xml', referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
            '',
        ]);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string, array{loc: string, lastmod: string|null}> $entries
     * @param array<string, string|int>                               $parameters
     */
    private function addSitemapEntry(
        array &$entries,
        string $route,
        array $parameters = [],
        ?\DateTimeInterface $lastModifiedAt = null,
    ): void {
        $url = $this->generateUrl($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        $entries[$url] = [
            'loc' => $url,
            'lastmod' => $lastModifiedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
