<?php

namespace App\Controller\Admin;

use App\Enum\DestinationType;
use App\Repository\DestinationRepository;
use App\Security\Voter\AdminAccessVoter;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminHubController extends AbstractController
{
    public function __construct(
        private readonly DestinationRepository $destinationRepository,
    ) {
    }

    #[Route('/admin/quick', name: 'admin_quick_index', methods: ['GET'])]
    public function quick(Request $request): Response
    {
        $quickType = $this->quickType($request);
        $quickMode = $this->quickMode($request);

        return $this->render('admin/quick/index.html.twig', [
            'quick_type' => $quickType,
            'quick_mode' => $quickMode,
            'quick_labels' => $quickType !== null ? $this->quickLabels($quickType) : null,
            'default_title' => $quickType !== null ? $this->defaultTitle($quickType) : null,
            'start_route' => $quickType === 'city_visit' ? 'admin_quick_city_visit_start' : 'admin_quick_hike_start',
            'start_token_id' => $quickType === 'city_visit' ? 'quick_city_visit_start' : 'quick_hike_start',
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']),
            'destination_quick_create' => $quickType !== null ? $this->emptyDestinationQuickCreateData($quickType) : [],
        ]);
    }

    #[Route('/admin/studio', name: 'admin_studio_index', methods: ['GET'])]
    public function studio(): Response
    {
        return $this->render('admin/studio/index.html.twig');
    }

    private function quickType(Request $request): ?string
    {
        $type = $request->query->getString('type');

        return in_array($type, ['hike', 'city_visit'], true) ? $type : null;
    }

    private function quickMode(Request $request): string
    {
        $mode = $request->query->getString('mode');

        return in_array($mode, ['terrain', 'distance'], true) ? $mode : 'choice';
    }

    /** @return array<string, string> */
    private function quickLabels(string $quickType): array
    {
        if ($quickType === 'city_visit') {
            return [
                'content' => 'visite de ville',
                'title' => 'Créer une visite de ville',
                'terrainTitle' => 'Démarrer une visite de ville terrain',
                'terrainButton' => 'Démarrer une visite de ville terrain',
                'distanceTitle' => 'Créer une visite de ville à distance',
                'distanceButton' => 'Créer une visite de ville à distance',
                'locationTitle' => 'Où se situe cette visite ?',
                'locationText' => 'Recherchez une commune française. Elle servira à situer la visite. Vous pourrez ajouter une destination plus précise plus tard.',
                'locationSubmit' => 'Créer la visite dans cette commune',
                'notesLabel' => 'Note de visite',
            ];
        }

        return [
            'content' => 'randonnée',
            'title' => 'Créer une randonnée',
            'terrainTitle' => 'Démarrer une randonnée terrain',
            'terrainButton' => 'Démarrer une randonnée terrain',
            'distanceTitle' => 'Créer une randonnée à distance',
            'distanceButton' => 'Créer une randonnée à distance',
            'locationTitle' => 'Où se situe cette randonnée ?',
            'locationText' => 'Recherchez une commune française. Elle servira à situer la randonnée. Vous pourrez ajouter une destination plus précise plus tard.',
            'locationSubmit' => 'Créer la randonnée dans cette commune',
            'notesLabel' => 'Note de départ',
        ];
    }

    private function defaultTitle(string $quickType): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));

        return $quickType === 'city_visit'
            ? sprintf('Visite de ville du %s', $now->format('d/m/Y à H\hi'))
            : sprintf('Randonnée du %s', $now->format('d/m/Y à H\hi'));
    }

    /** @return array<string, string> */
    private function destinationTypeOptions(): array
    {
        return [
            DestinationType::Country->value => 'Pays',
            DestinationType::Region->value => 'Région / zone',
            DestinationType::Department->value => 'Département / province',
            DestinationType::City->value => 'Ville / commune',
            DestinationType::Area->value => 'Zone / lieu précis',
        ];
    }

    /**
     * @return array{
     *     contextType: string,
     *     contextId: string,
     *     targetType: string,
     *     targetId: string,
     *     parent: string,
     *     name: string,
     *     countryName: string,
     *     regionName: string,
     *     departmentName: string,
     *     cityName: string,
     *     areaName: string,
     *     code: string,
     *     latitude: string,
     *     longitude: string
     * }
     */
    private function emptyDestinationQuickCreateData(string $quickType): array
    {
        $contextType = $quickType === 'city_visit' ? 'quick_city_visit' : 'quick_hike';

        return [
            'contextType' => $contextType,
            'contextId' => '',
            'targetType' => $contextType,
            'targetId' => '',
            'parent' => '',
            'name' => '',
            'countryName' => '',
            'regionName' => '',
            'departmentName' => '',
            'cityName' => '',
            'areaName' => '',
            'code' => '',
            'latitude' => '',
            'longitude' => '',
        ];
    }
}
