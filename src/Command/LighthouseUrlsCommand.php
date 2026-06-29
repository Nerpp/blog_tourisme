<?php

namespace App\Command;

use App\Entity\Destination;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'app:lighthouse:urls',
    description: 'Liste les URL canoniques publiques à auditer avec Lighthouse.',
)]
final class LighthouseUrlsCommand extends Command
{
    private const int MAX_PUBLIC_ENTITIES = PHP_INT_MAX;

    /** @var list<string> */
    private const array DYNAMIC_TYPES = ['destination', 'article', 'hike', 'city-visit', 'place'];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly DestinationRepository $destinationRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly HikeDraftRepository $hikeDraftRepository,
        private readonly CityVisitDraftRepository $cityVisitDraftRepository,
        private readonly PlaceRepository $placeRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format de sortie : json ou tsv.', 'json')
            ->addOption(
                'limit-per-type',
                null,
                InputOption::VALUE_REQUIRED,
                'Limite les pages dynamiques de chaque type pour une campagne représentative (0 = toutes).',
                '0',
            )
            ->addOption(
                'max-urls',
                null,
                InputOption::VALUE_REQUIRED,
                'Limite finale réservée aux validations courtes (0 = toutes).',
                '0',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? strtolower(trim($formatOption)) : '';
        if (!in_array($format, ['json', 'tsv'], true)) {
            $output->writeln('<error>Le format doit être "json" ou "tsv".</error>');

            return Command::INVALID;
        }

        $limit = $this->nonNegativeInt($input->getOption('limit-per-type'));
        if ($limit === null) {
            $output->writeln('<error>L’option --limit-per-type doit être un entier positif ou nul.</error>');

            return Command::INVALID;
        }
        $maxUrls = $this->nonNegativeInt($input->getOption('max-urls'));
        if ($maxUrls === null) {
            $output->writeln('<error>L’option --max-urls doit être un entier positif ou nul.</error>');

            return Command::INVALID;
        }

        $urls = $this->discoverUrls();
        if ($limit > 0) {
            $urls = $this->limitDynamicTypes($urls, $limit);
        }
        if ($maxUrls > 0) {
            $urls = array_slice($urls, 0, $maxUrls);
        }

        if ($format === 'tsv') {
            $output->writeln("id\ttype\ttitle\turl");
            foreach ($urls as $url) {
                $output->writeln(implode("\t", [
                    $url['id'],
                    $url['type'],
                    $this->tsvValue($url['title']),
                    $url['url'],
                ]));
            }

            return Command::SUCCESS;
        }

        $payload = [
            'schemaVersion' => 1,
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'total' => count($urls),
            'filters' => [
                'limitPerType' => $limit,
                'maxUrls' => $maxUrls,
            ],
            'urls' => $urls,
        ];

        $output->writeln((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id: string, type: string, title: string, url: string}>
     */
    private function discoverUrls(): array
    {
        $urls = [];

        $this->addUrl($urls, 'home', 'home', 'Accueil', 'app_home');
        foreach ([
            ['listing-articles', 'Articles', 'app_article_index'],
            ['listing-destinations', 'Destinations', 'app_destination_index'],
            ['listing-hikes', 'Randonnées', 'app_hike_index'],
            ['listing-city-visits', 'Visites', 'app_city_visit_index'],
            ['listing-places', 'Lieux', 'app_place_index'],
        ] as [$id, $title, $route]) {
            $this->addUrl($urls, $id, 'listing', $title, $route);
        }

        $rootDestinations = $this->destinationRepository->findRootDestinations();
        $destinationCounts = $this->destinationRepository->findCumulativeContentCountsForTree($rootDestinations);
        foreach ($this->flattenDestinations($rootDestinations) as $destination) {
            $id = $destination->getId();
            $slug = $destination->getSlug();
            if ($id === null || $slug === null || $slug === '' || ($destinationCounts[$id]['total'] ?? 0) === 0) {
                continue;
            }

            $this->addUrl(
                $urls,
                'destination-'.$slug,
                'destination',
                (string) $destination->getName(),
                'app_destination_show',
                ['slug' => $slug],
            );
        }

        foreach ($this->articleRepository->findPublishedForListing(null, self::MAX_PUBLIC_ENTITIES) as $article) {
            $slug = $article->getSlug();
            if ($slug !== null && $slug !== '') {
                $this->addUrl($urls, 'article-'.$slug, 'article', (string) $article->getTitle(), 'app_article_show', ['slug' => $slug]);
            }
        }

        foreach ($this->hikeDraftRepository->findPublicForListing(null, self::MAX_PUBLIC_ENTITIES) as $hike) {
            $slug = $hike->getSlug();
            if ($slug !== null && $slug !== '') {
                $this->addUrl($urls, 'hike-'.$slug, 'hike', (string) $hike->getTitle(), 'app_hike_show', ['slug' => $slug]);
            }
        }

        foreach ($this->cityVisitDraftRepository->findPublicForListing(null, self::MAX_PUBLIC_ENTITIES) as $cityVisit) {
            $slug = $cityVisit->getSlug();
            if ($slug !== null && $slug !== '') {
                $this->addUrl($urls, 'city-visit-'.$slug, 'city-visit', (string) $cityVisit->getTitle(), 'app_city_visit_show', ['slug' => $slug]);
            }
        }

        foreach ($this->placeRepository->findPublished(limit: self::MAX_PUBLIC_ENTITIES) as $place) {
            $slug = $place->getSlug();
            if ($slug !== null && $slug !== '') {
                $this->addUrl($urls, 'place-'.$slug, 'place', (string) $place->getName(), 'app_place_show', ['slug' => $slug]);
            }
        }

        $deduplicated = [];
        foreach ($urls as $url) {
            $deduplicated[$url['url']] ??= $url;
        }

        return array_values($deduplicated);
    }

    /**
     * @param list<array{id: string, type: string, title: string, url: string}> $urls
     * @param array<string, scalar> $parameters
     */
    private function addUrl(
        array &$urls,
        string $id,
        string $type,
        string $title,
        string $route,
        array $parameters = [],
    ): void {
        $urls[] = [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'url' => $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH),
        ];
    }

    /** @param list<Destination> $roots
     *  @return list<Destination>
     */
    private function flattenDestinations(array $roots): array
    {
        $destinations = $roots;

        for ($index = 0; $index < count($destinations); ++$index) {
            foreach ($destinations[$index]->getChildren() as $child) {
                $destinations[] = $child;
            }
        }

        usort($destinations, static fn (Destination $first, Destination $second): int => strcasecmp(
            (string) $first->getName(),
            (string) $second->getName(),
        ));

        return $destinations;
    }

    /**
     * @param list<array{id: string, type: string, title: string, url: string}> $urls
     * @return list<array{id: string, type: string, title: string, url: string}>
     */
    private function limitDynamicTypes(array $urls, int $limit): array
    {
        $counts = [];

        return array_values(array_filter($urls, static function (array $url) use (&$counts, $limit): bool {
            if (!in_array($url['type'], self::DYNAMIC_TYPES, true)) {
                return true;
            }

            $counts[$url['type']] = ($counts[$url['type']] ?? 0) + 1;

            return $counts[$url['type']] <= $limit;
        }));
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function tsvValue(string $value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', $value);
    }
}
