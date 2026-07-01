<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPoint;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\MediaRole;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class CityVisitFixtures extends Fixture implements DependentFixtureInterface
{
    public const COLLIOURE_REFERENCE = 'city_visit.collioure-a-pied';
    public const PERPIGNAN_REFERENCE = 'city_visit.perpignan-historique';
    public const CERET_REFERENCE = 'city_visit.ceret-centre-ancien';
    public const BANYULS_REFERENCE = 'city_visit.banyuls-port-front-mer';
    public const DRAFT_REFERENCE = 'city_visit.brouillon';
    public const LIGHTHOUSE_REFERENCE = self::COLLIOURE_REFERENCE;
    public const LIGHTHOUSE_SLUG = 'visiter-collioure-a-pied';

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);

        $visits = [
            self::COLLIOURE_REFERENCE => [
                'title' => 'Visiter Collioure à pied',
                'slug' => self::LIGHTHOUSE_SLUG,
                'status' => CityVisitDraftStatus::Finished,
                'destination' => DestinationFixtures::COLLIOURE_REFERENCE,
                'notes' => "Durée estimée : 2h30\nIntroduction : boucle pietonne entre port, patrimoine et points de vue.",
                'finishedAt' => new DateTimeImmutable('-10 days 17:00'),
                'media' => [MediaAssetFixtures::RUELLE_REFERENCE, MediaAssetFixtures::MER_REFERENCE],
                'article' => ArticleFixtures::COLLIOURE_ONE_DAY_REFERENCE,
                'points' => [
                    [CityVisitPointType::Start, 'Château royal', 42.52613, 3.08297, 'Depart devant le monument.', 1],
                    [CityVisitPointType::Square, 'Port de Collioure', 42.52709, 3.08434, 'Ambiance portuaire et barques.', 2],
                    [CityVisitPointType::Church, 'Église Notre-Dame-des-Anges', 42.52773, 3.08545, 'Clocher emblematique.', 3],
                    [CityVisitPointType::Photo, 'Ruelle colorée', 42.52682, 3.08376, 'Point photo dans le centre.', 4],
                    [CityVisitPointType::Viewpoint, 'Point de vue final', 42.52310, 3.08802, 'Vue sur la baie.', 5],
                ],
            ],
            self::PERPIGNAN_REFERENCE => [
                'title' => 'Perpignan historique',
                'slug' => 'perpignan-historique',
                'status' => CityVisitDraftStatus::Finished,
                'destination' => DestinationFixtures::PERPIGNAN_REFERENCE,
                'notes' => "Durée estimée : 3h\nParcours urbain pour tester une visite avec plusieurs monuments.",
                'finishedAt' => new DateTimeImmutable('-9 days 16:00'),
                'media' => [MediaAssetFixtures::CHATEAU_REFERENCE, MediaAssetFixtures::RUELLE_REFERENCE],
                'article' => ArticleFixtures::ARTICLE_NO_IMAGE_REFERENCE,
                'points' => [
                    [CityVisitPointType::Start, 'Castillet', 42.70073, 2.89564, 'Porte monumentale du centre.', 1],
                    [CityVisitPointType::Monument, 'Loge de Mer', 42.69932, 2.89510, 'Halte patrimoine.', 2],
                    [CityVisitPointType::Church, 'Cathédrale Saint-Jean-Baptiste', 42.69979, 2.89783, 'Etape religieuse.', 3],
                    [CityVisitPointType::Monument, 'Palais des Rois de Majorque', 42.69523, 2.89555, 'Fin de parcours sur les hauteurs.', 4],
                ],
            ],
            self::CERET_REFERENCE => [
                'title' => 'Céret et son centre ancien',
                'slug' => 'ceret-et-son-centre-ancien',
                'status' => CityVisitDraftStatus::Finished,
                'destination' => DestinationFixtures::CERET_REFERENCE,
                'notes' => 'Durée estimée : 1h45. Marche urbaine facile autour des places, platanes et ruelles.',
                'finishedAt' => new DateTimeImmutable('-8 days 14:00'),
                'media' => [MediaAssetFixtures::VILLAGE_REFERENCE],
                'article' => null,
                'points' => [
                    [CityVisitPointType::Start, 'Place des Neuf Jets', 42.48527, 2.74804, 'Depart dans le centre.', 1],
                    [CityVisitPointType::Museum, 'Musée d art moderne', 42.48608, 2.74938, 'Repere culturel.', 2],
                    [CityVisitPointType::Square, 'Boulevard du marché', 42.48595, 2.74882, 'Ambiance de marche.', 3],
                    [CityVisitPointType::End, 'Pont du Diable', 42.49223, 2.75043, 'Fin panoramique.', 4],
                ],
            ],
            self::BANYULS_REFERENCE => [
                'title' => 'Banyuls entre port et front de mer',
                'slug' => 'banyuls-entre-port-et-front-de-mer',
                'status' => CityVisitDraftStatus::Finished,
                'destination' => DestinationFixtures::BANYULS_SUR_MER_REFERENCE,
                'notes' => 'Durée estimée : 1h30. Parcours compact pour tester les points proches.',
                'finishedAt' => new DateTimeImmutable('-7 days 14:00'),
                'media' => [MediaAssetFixtures::MER_REFERENCE],
                'article' => ArticleFixtures::ALBERES_REFERENCE,
                'points' => [
                    [CityVisitPointType::Start, 'Port de Banyuls', 42.48376, 3.12897, 'Depart cote port.', 1],
                    [CityVisitPointType::Photo, 'Front de mer', 42.48268, 3.13043, 'Promenade et photo.', 2],
                    [CityVisitPointType::Viewpoint, 'Vignes en balcon', 42.48853, 3.09689, 'Vue sur les terrasses.', 3],
                    [CityVisitPointType::End, 'Retour plage centrale', 42.48291, 3.12712, 'Fin de parcours.', 4],
                ],
            ],
            self::DRAFT_REFERENCE => [
                'title' => 'Visite brouillon non publique',
                'slug' => 'visite-brouillon-non-publique',
                'status' => CityVisitDraftStatus::Draft,
                'destination' => DestinationFixtures::CARCASSONNE_REFERENCE,
                'notes' => 'Brouillon conserve pour tester les filtres de publication.',
                'finishedAt' => null,
                'media' => [MediaAssetFixtures::CHATEAU_REFERENCE],
                'article' => null,
                'points' => [
                    [CityVisitPointType::Start, 'Porte Narbonnaise', 43.20666, 2.36522, 'Point provisoire.', 1],
                    [CityVisitPointType::Monument, 'Remparts à compléter', 43.20760, 2.36392, 'Point non publie.', 2],
                ],
            ],
        ];

        foreach ($visits as $reference => $data) {
            $visit = (new CityVisitDraft())
                ->setTitle($data['title'])
                ->setSlug($data['slug'])
                ->setStatus($data['status'])
                ->setCreatedBy($admin)
                ->setDestination($this->getDestination($data['destination']))
                ->setGeographicDestination($this->getDestination($data['destination']))
                ->setDetectedCommuneName($this->getDestination($data['destination'])->getName())
                ->setDetectedCommuneCode($this->getDestination($data['destination'])->getCode())
                ->setDetectedDepartmentName($data['destination'] === DestinationFixtures::CARCASSONNE_REFERENCE ? 'Aude' : 'Pyrénées-Orientales')
                ->setDetectedRegionName('Occitanie')
                ->setNotes($data['notes'])
                ->setGoogleMapsUrl(null)
                ->setFinishedAt($data['finishedAt']);

            foreach ($data['points'] as $pointData) {
                $visit->addPoint($this->createPoint($pointData, $visit));
            }

            $manager->persist($visit);
            $this->addReference($reference, $visit);

            foreach ($data['media'] as $position => $mediaReference) {
                $manager->persist((new CityVisitDraftMedia())
                    ->setCityVisitDraft($visit)
                    ->setMediaAsset($this->getMedia($mediaReference))
                    ->setRole($position === 0 ? MediaRole::Cover : MediaRole::Gallery)
                    ->setPosition($position));
            }

            if (is_string($data['article'])) {
                $manager->persist((new ArticleCityVisit())
                    ->setArticle($this->getArticle($data['article']))
                    ->setCityVisitDraft($visit)
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
     * @param array{0: CityVisitPointType, 1: string, 2: float, 3: float, 4: string, 5: int} $data
     */
    private function createPoint(array $data, CityVisitDraft $visit): CityVisitPoint
    {
        return (new CityVisitPoint())
            ->setType($data[0])
            ->setTitle($data[1])
            ->setLatitude($data[2])
            ->setLongitude($data[3])
            ->setNote($data[4])
            ->setDetectedCommuneName($visit->getDetectedCommuneName())
            ->setDetectedCommuneCode($visit->getDetectedCommuneCode())
            ->setDetectedDepartmentName($visit->getDetectedDepartmentName())
            ->setDetectedRegionName('Occitanie')
            ->setPosition($data[5])
            ->setAccuracy(7.0)
            ->setCreatedAt(new DateTimeImmutable(sprintf('2026-02-%02d 10:00:00', min(28, $data[5]))));
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
