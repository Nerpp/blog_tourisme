<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\PrevisionDestination;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class PrevisionDestinationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $base = new DateTimeImmutable('2026-01-10 09:00:00');
        $items = [
            [
                'title' => 'Randonnée à repérer autour de Montner',
                'status' => PrevisionDestination::STATUS_TO_CHECK,
                'source' => PrevisionDestination::SOURCE_MANUAL,
                'notes' => 'Repérer une boucle accessible au printemps avec plusieurs points de vue. Vérifier l ombre disponible, la lisibilité du balisage, le stationnement et la possibilité de créer une courte variante familiale pour les journées très chaudes.',
                'commune' => 'Montner',
                'insee' => '66116',
                'postal' => '66720',
                'lat' => 42.74386,
                'lng' => 2.68144,
                'accuracy' => 8.0,
                'priority' => PrevisionDestination::PRIORITY_HIGH,
                'period' => 'Printemps 2026',
            ],
            [
                'title' => 'Visite potentielle de Collioure hors saison',
                'status' => PrevisionDestination::STATUS_TO_VISIT,
                'source' => PrevisionDestination::SOURCE_SEARCH,
                'notes' => 'Comparer les ambiances du matin et de fin de journée pour mettre à jour les conseils photo.',
                'commune' => 'Collioure',
                'insee' => '66053',
                'postal' => '66190',
                'lat' => 42.52505,
                'lng' => 3.08316,
                'accuracy' => 12.0,
                'priority' => PrevisionDestination::PRIORITY_MEDIUM,
                'period' => 'Hiver 2026',
            ],
            [
                'title' => 'Sentier côtier à vérifier',
                'status' => PrevisionDestination::STATUS_SPOTTED,
                'source' => PrevisionDestination::SOURCE_GPS,
                'notes' => 'Trace GPS validée sur carte, reste à contrôler les passages exposés.',
                'commune' => 'Banyuls-sur-Mer',
                'insee' => '66016',
                'postal' => '66650',
                'lat' => 42.48376,
                'lng' => 3.12897,
                'accuracy' => 5.0,
                'priority' => PrevisionDestination::PRIORITY_HIGH,
                'period' => 'Avril 2026',
            ],
            [
                'title' => 'Village à documenter : Céret',
                'status' => PrevisionDestination::STATUS_IDEA,
                'source' => PrevisionDestination::SOURCE_MANUAL,
                'notes' => 'Préparer une visite autour du marché, du musée et du pont.',
                'commune' => 'Céret',
                'insee' => '66049',
                'postal' => '66400',
                'lat' => 42.48527,
                'lng' => 2.74804,
                'accuracy' => 15.0,
                'priority' => PrevisionDestination::PRIORITY_MEDIUM,
                'period' => 'Mai 2026',
            ],
            [
                'title' => 'Prévision sans note',
                'status' => PrevisionDestination::STATUS_IDEA,
                'source' => PrevisionDestination::SOURCE_MANUAL,
                'notes' => null,
                'commune' => null,
                'insee' => null,
                'postal' => null,
                'lat' => null,
                'lng' => null,
                'accuracy' => null,
                'priority' => PrevisionDestination::PRIORITY_LOW,
                'period' => null,
            ],
            [
                'title' => 'Commune choisie sans coordonnées validées',
                'status' => PrevisionDestination::STATUS_TO_CHECK,
                'source' => PrevisionDestination::SOURCE_SEARCH,
                'notes' => 'Tester la carte masquée ou incomplète tant que le point GPS n est pas valide.',
                'commune' => 'Peyrestortes',
                'insee' => '66141',
                'postal' => '66600',
                'lat' => null,
                'lng' => null,
                'accuracy' => null,
                'priority' => PrevisionDestination::PRIORITY_LOW,
                'period' => 'À planifier',
            ],
            [
                'title' => 'Coordonnées validées à Saint-Laurent',
                'status' => PrevisionDestination::STATUS_TO_VISIT,
                'source' => PrevisionDestination::SOURCE_MANUAL_MAP,
                'notes' => 'Point placé manuellement sur la carte et prêt pour une sortie rapide.',
                'commune' => 'Saint-Laurent-de-la-Salanque',
                'insee' => '66180',
                'postal' => '66250',
                'lat' => 42.77285,
                'lng' => 2.98983,
                'accuracy' => 6.0,
                'priority' => PrevisionDestination::PRIORITY_MEDIUM,
                'period' => 'Juin 2026',
            ],
            [
                'title' => 'Ancienne idée abandonnée',
                'status' => PrevisionDestination::STATUS_ABANDONED,
                'source' => PrevisionDestination::SOURCE_MANUAL,
                'notes' => 'Idée conservée pour tester les filtres d archive et d abandon.',
                'commune' => 'Montpellier',
                'insee' => '34172',
                'postal' => '34000',
                'lat' => 43.61092,
                'lng' => 3.87723,
                'accuracy' => 20.0,
                'priority' => PrevisionDestination::PRIORITY_LOW,
                'period' => 'Reporté',
            ],
        ];

        foreach ($items as $index => $data) {
            $createdAt = $base->modify(sprintf('+%d days', $index));
            $manager->persist((new PrevisionDestination())
                ->setTitle($data['title'])
                ->setStatus($data['status'])
                ->setSource($data['source'])
                ->setNotes($data['notes'])
                ->setCountry('France')
                ->setRegion('Occitanie')
                ->setDepartment($data['commune'] === 'Montpellier' ? 'Hérault' : 'Pyrénées-Orientales')
                ->setCommune($data['commune'])
                ->setInseeCode($data['insee'])
                ->setPostalCode($data['postal'])
                ->setLatitude($data['lat'])
                ->setLongitude($data['lng'])
                ->setGpsAccuracy($data['accuracy'])
                ->setPriority($data['priority'])
                ->setPlannedPeriod($data['period'])
                ->setCreatedAt($createdAt)
                ->setUpdatedAt($createdAt->modify('+2 hours')));
        }

        $manager->flush();
    }
}
