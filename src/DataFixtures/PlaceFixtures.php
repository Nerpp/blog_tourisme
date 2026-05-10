<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use App\Entity\PlaceTag;
use App\Entity\Tag;
use App\Enum\ContentStatus;
use App\Enum\MediaRole;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class PlaceFixtures extends Fixture implements DependentFixtureInterface
{
    public const FORT_SAINT_ELME_REFERENCE = 'place.fort-saint-elme';
    public const CHATEAU_ROYAL_COLLIOURE_REFERENCE = 'place.chateau-royal-collioure';
    public const PLAGE_BORAMAR_REFERENCE = 'place.plage-boramar';
    public const ORGUES_ILLE_SUR_TET_REFERENCE = 'place.orgues-ille-sur-tet';
    public const LAC_BOUILLOUSES_REFERENCE = 'place.lac-des-bouillouses';

    public function load(ObjectManager $manager): void
    {
        $publishedAt = new DateTimeImmutable('-18 days');

        $fortSaintElme = $this->createPlace(
            name: 'Fort Saint-Elme',
            slug: 'fort-saint-elme',
            destination: $this->getDestination(DestinationFixtures::COLLIOURE_REFERENCE),
            category: $this->getCategory(CategoryFixtures::MONUMENT_REFERENCE),
            shortDescription: 'Fort historique dominant Collioure et la cote Vermeille.',
            description: <<<TEXT
Le Fort Saint-Elme veille sur Collioure depuis une position spectaculaire entre mer et montagne. Sa silhouette massive rappelle le role strategique de la cote Vermeille, longtemps disputee et surveillee depuis les hauteurs.

La visite permet de comprendre l architecture militaire du site, de parcourir ses salles et de profiter de vues tres ouvertes sur Collioure, Port-Vendres et les reliefs des Alberes. C est une halte ideale pour associer patrimoine catalan, photographie et lecture du paysage.
TEXT,
            address: 'Route de Port-Vendres, Collioure',
            latitude: 42.51909,
            longitude: 3.08812,
            visitDurationMinutes: 90,
            difficulty: PlaceDifficulty::Medium,
            priceType: PriceType::Paid,
            featuredImage: $this->getMedia(MediaAssetFixtures::FORT_SAINT_ELME_360_REFERENCE),
            seoTitle: 'Visiter le Fort Saint-Elme a Collioure',
            seoDescription: 'Infos pratiques, panorama et conseils pour visiter le Fort Saint-Elme au-dessus de Collioure.',
            publishedAt: $publishedAt,
        );
        $manager->persist($fortSaintElme);
        $this->addReference(self::FORT_SAINT_ELME_REFERENCE, $fortSaintElme);
        $this->linkTags($manager, $fortSaintElme, [
            TagFixtures::PATRIMOINE_CATALAN_REFERENCE,
            TagFixtures::PHOTO_REFERENCE,
            TagFixtures::PANORAMIQUE_REFERENCE,
            TagFixtures::INCONTOURNABLE_REFERENCE,
        ]);
        $this->linkMedia($manager, $fortSaintElme, [
            [MediaAssetFixtures::FORT_SAINT_ELME_360_REFERENCE, MediaRole::Immersive, 0],
            [MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE, MediaRole::Cover, 1],
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Gallery, 2],
            [MediaAssetFixtures::PORT_COLLIOURE_WIDE_REFERENCE, MediaRole::MapPreview, 3],
        ]);

        $chateauRoyal = $this->createPlace(
            name: 'Château Royal de Collioure',
            slug: 'chateau-royal-de-collioure',
            destination: $this->getDestination(DestinationFixtures::COLLIOURE_REFERENCE),
            category: $this->getCategory(CategoryFixtures::MONUMENT_REFERENCE),
            shortDescription: 'Ancienne residence fortifiee au bord de la baie de Collioure.',
            description: <<<TEXT
Le Château Royal occupe une place centrale dans le paysage de Collioure. Pose entre les plages, les barques et les ruelles du village, il raconte plusieurs siecles d histoire mediterraneenne.

La visite alterne cours, remparts et salles voutees. Elle se prete bien a une sortie en famille, avec de nombreux points de vue sur le clocher, les maisons colorees et les eaux calmes de la baie.
TEXT,
            address: 'Quai de l Amiraute, Collioure',
            latitude: 42.52613,
            longitude: 3.08297,
            visitDurationMinutes: 75,
            difficulty: PlaceDifficulty::Easy,
            priceType: PriceType::Paid,
            featuredImage: $this->getMedia(MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE),
            seoTitle: 'Château Royal de Collioure : visite et conseils',
            seoDescription: 'Preparez votre visite du Château Royal de Collioure, monument majeur de la cote Vermeille.',
            publishedAt: $publishedAt->modify('+1 day'),
        );
        $manager->persist($chateauRoyal);
        $this->addReference(self::CHATEAU_ROYAL_COLLIOURE_REFERENCE, $chateauRoyal);
        $this->linkTags($manager, $chateauRoyal, [
            TagFixtures::PATRIMOINE_CATALAN_REFERENCE,
            TagFixtures::FAMILLE_REFERENCE,
            TagFixtures::INCONTOURNABLE_REFERENCE,
        ]);
        $this->linkMedia($manager, $chateauRoyal, [
            [MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Gallery, 1],
        ]);

        $plageBoramar = $this->createPlace(
            name: 'Plage de Boramar',
            slug: 'plage-de-boramar',
            destination: $this->getDestination(DestinationFixtures::COLLIOURE_REFERENCE),
            category: $this->getCategory(CategoryFixtures::PLAGE_REFERENCE),
            shortDescription: 'Petite plage centrale avec vue directe sur le clocher de Collioure.',
            description: <<<TEXT
La plage de Boramar est l une des images les plus connues de Collioure. Les galets, les barques, le clocher et les facades colorees composent un decor immediatement reconnaissable.

Elle est parfaite pour une pause courte pendant une journee de visite, un bain en saison ou une lumiere de fin de journee. Le site est tres frequenté l ete, mais garde beaucoup de charme tot le matin et hors saison.
TEXT,
            address: 'Plage de Boramar, Collioure',
            latitude: 42.52709,
            longitude: 3.08434,
            visitDurationMinutes: 45,
            difficulty: PlaceDifficulty::Easy,
            priceType: PriceType::Free,
            featuredImage: $this->getMedia(MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE),
            seoTitle: 'Plage de Boramar a Collioure',
            seoDescription: 'Ambiance, acces et conseils pour profiter de la plage de Boramar au coeur de Collioure.',
            publishedAt: $publishedAt->modify('+2 days'),
        );
        $manager->persist($plageBoramar);
        $this->addReference(self::PLAGE_BORAMAR_REFERENCE, $plageBoramar);
        $this->linkTags($manager, $plageBoramar, [
            TagFixtures::BORD_DE_MER_REFERENCE,
            TagFixtures::FAMILLE_REFERENCE,
            TagFixtures::GRATUIT_REFERENCE,
            TagFixtures::COUCHER_DE_SOLEIL_REFERENCE,
        ]);
        $this->linkMedia($manager, $plageBoramar, [
            [MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::PORT_COLLIOURE_WIDE_REFERENCE, MediaRole::Gallery, 1],
        ]);

        $orguesIlleSurTet = $this->createPlace(
            name: 'Orgues d’Ille-sur-Têt',
            slug: 'orgues-ille-sur-tet',
            destination: $this->getDestination(DestinationFixtures::ILLE_SUR_TET_REFERENCE),
            category: $this->getCategory(CategoryFixtures::NATURE_REFERENCE),
            shortDescription: 'Cheminées de fée et falaises minerales sculptees par l erosion.',
            description: <<<TEXT
Les Orgues d’Ille-sur-Têt offrent un paysage presque desertique au coeur du Roussillon. Les colonnes de roche tendre, faconnees par la pluie et le vent, forment un decor graphique qui change selon la lumiere.

Le parcours est court mais tres photogenique. Il se visite facilement en famille et constitue une belle parenthese nature entre Perpignan, la vallee de la Tet et les premiers reliefs du Conflent.
TEXT,
            address: 'Chemin de Regleilles, Ille-sur-Têt',
            latitude: 42.66924,
            longitude: 2.61731,
            visitDurationMinutes: 60,
            difficulty: PlaceDifficulty::Easy,
            priceType: PriceType::Paid,
            featuredImage: $this->getMedia(MediaAssetFixtures::COTE_VERMEILLE_180_REFERENCE),
            seoTitle: 'Visiter les Orgues d’Ille-sur-Têt',
            seoDescription: 'Conseils pratiques pour decouvrir les Orgues d’Ille-sur-Têt, site naturel spectaculaire du Roussillon.',
            publishedAt: $publishedAt->modify('+3 days'),
        );
        $manager->persist($orguesIlleSurTet);
        $this->addReference(self::ORGUES_ILLE_SUR_TET_REFERENCE, $orguesIlleSurTet);
        $this->linkTags($manager, $orguesIlleSurTet, [
            TagFixtures::NATURE_REFERENCE,
            TagFixtures::PHOTO_REFERENCE,
            TagFixtures::INCONTOURNABLE_REFERENCE,
        ]);
        $this->linkMedia($manager, $orguesIlleSurTet, [
            [MediaAssetFixtures::COTE_VERMEILLE_180_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Gallery, 1],
        ]);

        $lacBouillouses = $this->createPlace(
            name: 'Lac des Bouillouses',
            slug: 'lac-des-bouillouses',
            destination: $this->getDestination(DestinationFixtures::FONT_ROMEU_REFERENCE),
            category: $this->getCategory(CategoryFixtures::NATURE_REFERENCE),
            shortDescription: 'Grand lac d altitude entoure de forets, de sommets et de sentiers de randonnee.',
            description: <<<TEXT
Le lac des Bouillouses est une grande respiration de montagne au-dessus de Font-Romeu. L eau, les pins, les blocs de granit et les sommets de Cerdagne offrent un decor tres different du littoral.

Plusieurs itineraires partent du secteur, de la simple promenade au tour des lacs plus ambitieux. En haute saison, l acces est regule : il vaut mieux verifier les navettes et partir tot pour profiter de la lumiere du matin.
TEXT,
            address: 'Site classe des Bouillouses, Font-Romeu',
            latitude: 42.56067,
            longitude: 2.00338,
            visitDurationMinutes: 180,
            difficulty: PlaceDifficulty::Medium,
            priceType: PriceType::Free,
            featuredImage: $this->getMedia(MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE),
            seoTitle: 'Lac des Bouillouses : randonnee et conseils',
            seoDescription: 'Acces, idees de randonnee et conseils pour visiter le lac des Bouillouses depuis Font-Romeu.',
            publishedAt: $publishedAt->modify('+4 days'),
        );
        $manager->persist($lacBouillouses);
        $this->addReference(self::LAC_BOUILLOUSES_REFERENCE, $lacBouillouses);
        $this->linkTags($manager, $lacBouillouses, [
            TagFixtures::MONTAGNE_REFERENCE,
            TagFixtures::RANDONNEE_REFERENCE,
            TagFixtures::NATURE_REFERENCE,
            TagFixtures::PANORAMIQUE_REFERENCE,
        ]);
        $this->linkMedia($manager, $lacBouillouses, [
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::COTE_VERMEILLE_180_REFERENCE, MediaRole::Gallery, 1],
            [MediaAssetFixtures::PORT_COLLIOURE_WIDE_REFERENCE, MediaRole::MapPreview, 2],
        ]);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            DestinationFixtures::class,
            CategoryFixtures::class,
            TagFixtures::class,
            MediaAssetFixtures::class,
        ];
    }

    private function createPlace(
        string $name,
        string $slug,
        Destination $destination,
        Category $category,
        string $shortDescription,
        string $description,
        string $address,
        float $latitude,
        float $longitude,
        int $visitDurationMinutes,
        PlaceDifficulty $difficulty,
        PriceType $priceType,
        MediaAsset $featuredImage,
        string $seoTitle,
        string $seoDescription,
        DateTimeImmutable $publishedAt,
    ): Place {
        return (new Place())
            ->setName($name)
            ->setSlug($slug)
            ->setDestination($destination)
            ->setCategory($category)
            ->setShortDescription($shortDescription)
            ->setDescription($description)
            ->setAddress($address)
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setVisitDurationMinutes($visitDurationMinutes)
            ->setDifficulty($difficulty)
            ->setPriceType($priceType)
            ->setStatus(ContentStatus::Published)
            ->setFeaturedImage($featuredImage)
            ->setSeoTitle($seoTitle)
            ->setSeoDescription($seoDescription)
            ->setPublishedAt($publishedAt);
    }

    /**
     * @param list<string> $tagReferences
     */
    private function linkTags(ObjectManager $manager, Place $place, array $tagReferences): void
    {
        foreach ($tagReferences as $tagReference) {
            $placeTag = (new PlaceTag())
                ->setPlace($place)
                ->setTag($this->getTag($tagReference));

            $manager->persist($placeTag);
        }
    }

    /**
     * @param list<array{0: string, 1: MediaRole, 2: int}> $mediaLinks
     */
    private function linkMedia(ObjectManager $manager, Place $place, array $mediaLinks): void
    {
        foreach ($mediaLinks as [$mediaReference, $role, $position]) {
            $placeMedia = (new PlaceMedia())
                ->setPlace($place)
                ->setMediaAsset($this->getMedia($mediaReference))
                ->setRole($role)
                ->setPosition($position);

            $manager->persist($placeMedia);
        }
    }

    private function getDestination(string $reference): Destination
    {
        return $this->getReference($reference, Destination::class);
    }

    private function getCategory(string $reference): Category
    {
        return $this->getReference($reference, Category::class);
    }

    private function getTag(string $reference): Tag
    {
        return $this->getReference($reference, Tag::class);
    }

    private function getMedia(string $reference): MediaAsset
    {
        return $this->getReference($reference, MediaAsset::class);
    }
}
