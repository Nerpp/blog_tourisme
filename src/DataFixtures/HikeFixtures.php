<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\ArticleHike;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePoint;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use App\Enum\MediaRole;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class HikeFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    use TestFixtureGroup;

    public const CANIGOU_REFERENCE = 'hike.canigou-decouverte';
    public const COLLIOURE_BANYULS_REFERENCE = 'hike.sentier-cotier-collioure-banyuls';
    public const MONTNER_REFERENCE = 'hike.petite-boucle-montner';
    public const START_ONLY_REFERENCE = 'hike.seulement-point-depart';
    public const NO_MEDIA_REFERENCE = 'hike.sans-media';
    public const DRAFT_REFERENCE = 'hike.brouillon-admin';
    public const LIGHTHOUSE_REFERENCE = self::CANIGOU_REFERENCE;
    public const LIGHTHOUSE_SLUG = 'boucle-du-canigou-decouverte';

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);

        $hikes = [
            self::CANIGOU_REFERENCE => [
                'title' => 'Boucle du Canigou découverte',
                'slug' => self::LIGHTHOUSE_SLUG,
                'status' => HikeDraftStatus::Finished,
                'destination' => DestinationFixtures::PRADES_REFERENCE,
                'notes' => "Difficulté : moyenne\nDistance : 12.5 km\nDurée : 4h30\nDénivelé : 650 m\nBoucle de test avec plusieurs points GPS et galerie.",
                'finishedAt' => new DateTimeImmutable('-12 days 17:00'),
                'media' => [MediaAssetFixtures::MONTAGNE_REFERENCE, MediaAssetFixtures::FORET_REFERENCE],
                'article' => ArticleFixtures::MEDITERRANEAN_HIKE_REFERENCE,
                'points' => [
                    [HikePointType::Start, 'Départ de Prades', 42.61710, 2.42160, 'Parking et controle du materiel.', 'Prades', '66149', 1],
                    [HikePointType::Interest, 'Canal et vergers', 42.60980, 2.40520, 'Section douce pour lancer la boucle.', 'Prades', '66149', 2],
                    [HikePointType::Viewpoint, 'Vue sur le Canigou', 42.59550, 2.38680, 'Point panoramique principal.', 'Prades', '66149', 3],
                    [HikePointType::Rest, 'Pause ombragée', 42.60420, 2.39810, 'Zone utile par forte chaleur.', 'Prades', '66149', 4],
                    [HikePointType::End, 'Retour au départ', 42.61710, 2.42160, 'Fin de boucle.', 'Prades', '66149', 5],
                ],
            ],
            self::COLLIOURE_BANYULS_REFERENCE => [
                'title' => 'Sentier côtier de Collioure à Banyuls',
                'slug' => 'sentier-cotier-de-collioure-a-banyuls',
                'status' => HikeDraftStatus::Finished,
                'destination' => DestinationFixtures::COLLIOURE_REFERENCE,
                'notes' => "Difficulté : facile\nDistance : 8.2 km\nDurée : 2h45\nCarte GPS complete pour tester le fitBounds public.",
                'finishedAt' => new DateTimeImmutable('-11 days 15:00'),
                'media' => [MediaAssetFixtures::MER_REFERENCE, MediaAssetFixtures::COTE_VERMEILLE_180_REFERENCE],
                'article' => ArticleFixtures::ALBERES_REFERENCE,
                'points' => [
                    [HikePointType::Start, 'Plage de Boramar', 42.52709, 3.08434, 'Depart au bord de la baie.', 'Collioure', '66053', 1],
                    [HikePointType::Viewpoint, 'Fort Saint-Elme', 42.51909, 3.08812, 'Vue sur Collioure.', 'Collioure', '66053', 2],
                    [HikePointType::Photo, 'Anse de Paulilles', 42.50180, 3.12241, 'Point photo littoral.', 'Banyuls-sur-Mer', '66016', 3],
                    [HikePointType::End, 'Front de mer de Banyuls', 42.48376, 3.12897, 'Arrivée côté port.', 'Banyuls-sur-Mer', '66016', 4],
                ],
            ],
            self::MONTNER_REFERENCE => [
                'title' => 'Petite boucle de Montner',
                'slug' => 'petite-boucle-de-montner',
                'status' => HikeDraftStatus::Finished,
                'destination' => DestinationFixtures::MONTNER_REFERENCE,
                'notes' => "Difficulté : facile\nDistance : 4.8 km\nPoints d interet viticoles et belvedere.",
                'finishedAt' => new DateTimeImmutable('-9 days 12:00'),
                'media' => [MediaAssetFixtures::RANDONNEE_REFERENCE],
                'article' => ArticleFixtures::MEDITERRANEAN_HIKE_REFERENCE,
                'points' => [
                    [HikePointType::Start, 'Mairie de Montner', 42.74386, 2.68144, 'Depart au coeur du village.', 'Montner', '66116', 1],
                    [HikePointType::Interest, 'Vignes en terrasse', 42.74712, 2.67540, 'Point d interet paysage viticole.', 'Montner', '66116', 2],
                    [HikePointType::Viewpoint, 'Collines du Fenouillèdes', 42.75010, 2.68430, 'Vue ouverte pour tester le bouton Voir ce point.', 'Montner', '66116', 3],
                    [HikePointType::End, 'Retour village', 42.74386, 2.68144, 'Retour au depart.', 'Montner', '66116', 4],
                ],
            ],
            self::START_ONLY_REFERENCE => [
                'title' => 'Randonnée avec seulement point de départ',
                'slug' => 'randonnee-avec-seulement-point-de-depart',
                'status' => HikeDraftStatus::Finished,
                'destination' => DestinationFixtures::PEYRESTORTES_REFERENCE,
                'notes' => 'Cas limite : un seul point GPS pour tester les messages de carte incomplete.',
                'finishedAt' => new DateTimeImmutable('-8 days 10:00'),
                'media' => [MediaAssetFixtures::RANDONNEE_REFERENCE],
                'article' => null,
                'points' => [
                    [HikePointType::Start, 'Départ unique Peyrestortes', 42.75534, 2.85208, 'Aucun autre point volontairement.', 'Peyrestortes', '66141', 1],
                ],
            ],
            self::NO_MEDIA_REFERENCE => [
                'title' => 'Randonnée sans média',
                'slug' => 'randonnee-sans-media',
                'status' => HikeDraftStatus::Finished,
                'destination' => DestinationFixtures::SAINT_LAURENT_SALANQUE_REFERENCE,
                'notes' => 'Cas limite public sans media pour tester les fallbacks image.',
                'finishedAt' => new DateTimeImmutable('-7 days 16:00'),
                'media' => [],
                'article' => ArticleFixtures::ARTICLE_NO_IMAGE_REFERENCE,
                'points' => [
                    [HikePointType::Start, 'Marché de la Salanque', 42.77285, 2.98983, 'Depart urbain.', 'Saint-Laurent-de-la-Salanque', '66180', 1],
                    [HikePointType::Interest, 'Bord d étang', 42.77940, 3.00010, 'Transition vers les etangs.', 'Saint-Laurent-de-la-Salanque', '66180', 2],
                    [HikePointType::End, 'Retour centre', 42.77285, 2.98983, 'Retour au point de depart.', 'Saint-Laurent-de-la-Salanque', '66180', 3],
                ],
            ],
            self::DRAFT_REFERENCE => [
                'title' => 'Randonnée brouillon admin',
                'slug' => 'randonnee-brouillon-admin',
                'status' => HikeDraftStatus::Draft,
                'destination' => DestinationFixtures::PRADES_REFERENCE,
                'notes' => 'Brouillon non public pour tester les filtres.',
                'finishedAt' => null,
                'media' => [MediaAssetFixtures::FORET_REFERENCE],
                'article' => null,
                'points' => [
                    [HikePointType::Start, 'Point à vérifier', 42.62000, 2.43000, 'Coordonnees provisoires.', 'Prades', '66149', 1],
                    [HikePointType::Other, 'Trace à compléter', 42.62200, 2.43500, 'Point de brouillon.', 'Prades', '66149', 2],
                ],
            ],
        ];

        foreach ($hikes as $reference => $data) {
            $hike = (new HikeDraft())
                ->setTitle($data['title'])
                ->setSlug($data['slug'])
                ->setStatus($data['status'])
                ->setCreatedBy($admin)
                ->setDestination($this->getDestination($data['destination']))
                ->setGeographicDestination($this->getDestination($data['destination']))
                ->setDetectedCommuneName($data['points'][0][5])
                ->setDetectedCommuneCode($data['points'][0][6])
                ->setDetectedDepartmentName('Pyrénées-Orientales')
                ->setDetectedRegionName('Occitanie')
                ->setNotes($data['notes'])
                ->setFinishedAt($data['finishedAt']);

            foreach ($data['points'] as $pointData) {
                $hike->addPoint($this->createPoint($pointData));
            }

            $manager->persist($hike);
            $this->addReference($reference, $hike);

            foreach ($data['media'] as $position => $mediaReference) {
                $manager->persist((new HikeDraftMedia())
                    ->setHikeDraft($hike)
                    ->setMediaAsset($this->getMedia($mediaReference))
                    ->setRole($position === 0 ? MediaRole::Cover : MediaRole::Gallery)
                    ->setPosition($position));
            }

            if (is_string($data['article'])) {
                $manager->persist((new ArticleHike())
                    ->setArticle($this->getArticle($data['article']))
                    ->setHikeDraft($hike)
                    ->setRole('related')
                    ->setPosition(0));
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            DestinationFixtures::class,
            MediaAssetFixtures::class,
            ArticleFixtures::class,
        ];
    }

    /**
     * @param array{0: HikePointType, 1: string, 2: float, 3: float, 4: string, 5: string, 6: string, 7: int} $data
     */
    private function createPoint(array $data): HikePoint
    {
        return (new HikePoint())
            ->setType($data[0])
            ->setTitle($data[1])
            ->setLatitude($data[2])
            ->setLongitude($data[3])
            ->setNote($data[4])
            ->setDetectedCommuneName($data[5])
            ->setDetectedCommuneCode($data[6])
            ->setDetectedDepartmentName('Pyrénées-Orientales')
            ->setDetectedRegionName('Occitanie')
            ->setPosition($data[7])
            ->setAccuracy(8.0)
            ->setCreatedAt(new DateTimeImmutable(sprintf('2026-01-%02d 09:00:00', min(28, $data[7]))));
    }

    private function getUser(string $reference): User
    {
        return $this->getReference($reference, User::class);
    }

    private function getDestination(string $reference): Destination
    {
        return $this->getReference($reference, Destination::class);
    }

    private function getMedia(string $reference): MediaAsset
    {
        return $this->getReference($reference, MediaAsset::class);
    }

    private function getArticle(string $reference): Article
    {
        return $this->getReference($reference, Article::class);
    }
}
