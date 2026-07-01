<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Destination;
use App\Enum\DestinationType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class DestinationFixtures extends Fixture
{
    public const FRANCE_REFERENCE = 'destination.france';
    public const OCCITANIE_REFERENCE = 'destination.occitanie';
    public const PYRENEES_ORIENTALES_REFERENCE = 'destination.pyrenees-orientales';
    public const AUDE_REFERENCE = 'destination.aude';
    public const HERAULT_REFERENCE = 'destination.herault';
    public const HAUTE_GARONNE_REFERENCE = 'destination.haute-garonne';
    public const COLLIOURE_REFERENCE = 'destination.collioure';
    public const ARGELES_SUR_MER_REFERENCE = 'destination.argeles-sur-mer';
    public const PERPIGNAN_REFERENCE = 'destination.perpignan';
    public const ILLE_SUR_TET_REFERENCE = 'destination.ille-sur-tet';
    public const FONT_ROMEU_REFERENCE = 'destination.font-romeu';
    public const BANYULS_SUR_MER_REFERENCE = 'destination.banyuls-sur-mer';
    public const CERET_REFERENCE = 'destination.ceret';
    public const PRADES_REFERENCE = 'destination.prades';
    public const MONTNER_REFERENCE = 'destination.montner';
    public const PEYRESTORTES_REFERENCE = 'destination.peyrestortes';
    public const SAINT_LAURENT_SALANQUE_REFERENCE = 'destination.saint-laurent-de-la-salanque';
    public const CARCASSONNE_REFERENCE = 'destination.carcassonne';
    public const MONTPELLIER_REFERENCE = 'destination.montpellier';
    public const TOULOUSE_REFERENCE = 'destination.toulouse';
    public const LIGHTHOUSE_REFERENCE = self::PYRENEES_ORIENTALES_REFERENCE;
    public const LIGHTHOUSE_SLUG = 'pyrenees-orientales';

    public function load(ObjectManager $manager): void
    {
        $france = $this->createDestination(
            name: 'France',
            slug: 'france',
            type: DestinationType::Country,
            code: 'FR',
            description: 'Pays d Europe occidentale reconnu pour la richesse de ses paysages, de son patrimoine et de sa gastronomie.',
            latitude: 46.603354,
            longitude: 1.888334,
            seoTitle: 'Voyager en France',
            seoDescription: 'Decouvrez les destinations incontournables, les lieux a visiter et les conseils pour voyager en France.',
        );
        $manager->persist($france);
        $this->addReference(self::FRANCE_REFERENCE, $france);

        $occitanie = $this->createDestination(
            name: 'Occitanie',
            slug: 'occitanie',
            type: DestinationType::Region,
            parent: $france,
            code: '76',
            description: 'Region du sud de la France entre Mediterranee, Pyrenees, villages de caractere et grands espaces naturels.',
            latitude: 43.892723,
            longitude: 3.282762,
            seoTitle: 'Que voir en Occitanie ?',
            seoDescription: 'Idees de visites, villes, plages et randonnees pour preparer un sejour en Occitanie.',
        );
        $manager->persist($occitanie);
        $this->addReference(self::OCCITANIE_REFERENCE, $occitanie);

        $pyreneesOrientales = $this->createDestination(
            name: 'Pyrénées-Orientales',
            slug: self::LIGHTHOUSE_SLUG,
            type: DestinationType::Department,
            parent: $occitanie,
            code: '66',
            description: 'Departement catalan entre cote Vermeille, plaine du Roussillon, vallees de montagne et villages colores.',
            latitude: 42.625417,
            longitude: 2.506943,
            seoTitle: 'Visiter les Pyrénées-Orientales',
            seoDescription: 'Les plus beaux sites des Pyrénées-Orientales, de Collioure aux lacs d altitude.',
        );
        $manager->persist($pyreneesOrientales);
        $this->addReference(self::PYRENEES_ORIENTALES_REFERENCE, $pyreneesOrientales);

        $departments = [
            self::AUDE_REFERENCE => [
                'name' => 'Aude',
                'slug' => 'aude',
                'code' => '11',
                'description' => 'Departement entre cite medievale, Corbieres, canal du Midi et premiers reliefs pyreneens.',
                'latitude' => 43.1030,
                'longitude' => 2.4140,
                'seoTitle' => 'Visiter l Aude',
                'seoDescription' => 'Idees de visites et de balades dans l Aude, autour de Carcassonne et du canal du Midi.',
            ],
            self::HERAULT_REFERENCE => [
                'name' => 'Hérault',
                'slug' => 'herault',
                'code' => '34',
                'description' => 'Departement mediterraneen autour de Montpellier, entre etangs, garrigue et villages historiques.',
                'latitude' => 43.5912,
                'longitude' => 3.2584,
                'seoTitle' => 'Visiter l Hérault',
                'seoDescription' => 'Plages, villes et escapades nature dans l Hérault.',
            ],
            self::HAUTE_GARONNE_REFERENCE => [
                'name' => 'Haute-Garonne',
                'slug' => 'haute-garonne',
                'code' => '31',
                'description' => 'Departement de Toulouse a la haute vallee garonnaise, entre patrimoine urbain et portes des Pyrenees.',
                'latitude' => 43.3051,
                'longitude' => 1.2447,
                'seoTitle' => 'Visiter la Haute-Garonne',
                'seoDescription' => 'Toulouse, villages et idees de sorties en Haute-Garonne.',
            ],
        ];

        foreach ($departments as $reference => $data) {
            $department = $this->createDestination(
                name: $data['name'],
                slug: $data['slug'],
                type: DestinationType::Department,
                parent: $occitanie,
                code: $data['code'],
                description: $data['description'],
                latitude: $data['latitude'],
                longitude: $data['longitude'],
                seoTitle: $data['seoTitle'],
                seoDescription: $data['seoDescription'],
            );

            $manager->persist($department);
            $this->addReference($reference, $department);
        }

        $cities = [
            self::COLLIOURE_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Collioure',
                'slug' => 'collioure',
                'code' => '66053',
                'description' => 'Village de la cote Vermeille celebre pour son port, son chateau royal, ses ruelles colorees et sa lumiere.',
                'latitude' => 42.52505,
                'longitude' => 3.08316,
                'seoTitle' => 'Visiter Collioure',
                'seoDescription' => 'Que faire a Collioure : plages, patrimoine catalan, points de vue et bonnes idees de visite.',
            ],
            self::ARGELES_SUR_MER_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Argelès-sur-Mer',
                'slug' => 'argeles-sur-mer',
                'code' => '66008',
                'description' => 'Station balneaire familiale connue pour sa longue plage, son port et les premiers reliefs des Alberes.',
                'latitude' => 42.54714,
                'longitude' => 3.02253,
                'seoTitle' => 'Visiter Argelès-sur-Mer',
                'seoDescription' => 'Plages, sentiers et idees de sorties autour d Argelès-sur-Mer.',
            ],
            self::PERPIGNAN_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Perpignan',
                'slug' => 'perpignan',
                'code' => '66136',
                'description' => 'Ville catalane animee, porte d entree du Roussillon, avec un centre historique et des marches gourmands.',
                'latitude' => 42.68866,
                'longitude' => 2.89483,
                'seoTitle' => 'Visiter Perpignan',
                'seoDescription' => 'Patrimoine, quartiers et conseils pour decouvrir Perpignan et ses environs.',
            ],
            self::ILLE_SUR_TET_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Ille-sur-Têt',
                'slug' => 'ille-sur-tet',
                'code' => '66088',
                'description' => 'Petite ville du Roussillon connue pour ses orgues naturelles de roche friable sculptees par l erosion.',
                'latitude' => 42.67073,
                'longitude' => 2.62162,
                'seoTitle' => 'Visiter Ille-sur-Têt',
                'seoDescription' => 'Decouvrez les Orgues d Ille-sur-Têt et les paysages mineraux du Roussillon.',
            ],
            self::FONT_ROMEU_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Font-Romeu',
                'slug' => 'font-romeu',
                'code' => '66124',
                'description' => 'Station de montagne en Cerdagne, ideale pour la randonnee, les lacs d altitude et les activites de plein air.',
                'latitude' => 42.50564,
                'longitude' => 2.04147,
                'seoTitle' => 'Visiter Font-Romeu',
                'seoDescription' => 'Montagne, randonnees et grands paysages autour de Font-Romeu.',
            ],
            self::BANYULS_SUR_MER_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Banyuls-sur-Mer',
                'slug' => 'banyuls-sur-mer',
                'code' => '66016',
                'description' => 'Commune de la cote Vermeille connue pour son port, ses vignes en terrasse et les criques vers Paulilles.',
                'latitude' => 42.48376,
                'longitude' => 3.12897,
                'seoTitle' => 'Visiter Banyuls-sur-Mer',
                'seoDescription' => 'Port, front de mer, vignes et sentiers entre Banyuls-sur-Mer et la cote Vermeille.',
            ],
            self::CERET_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Céret',
                'slug' => 'ceret',
                'code' => '66049',
                'description' => 'Ville du Vallespir reputee pour son marche, son musee d art moderne et ses platanes.',
                'latitude' => 42.48527,
                'longitude' => 2.74804,
                'seoTitle' => 'Visiter Céret',
                'seoDescription' => 'Centre ancien, marche, art moderne et idees de visite a Céret.',
            ],
            self::PRADES_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Prades',
                'slug' => 'prades',
                'code' => '66149',
                'description' => 'Ville du Conflent au pied du Canigou, pratique pour rayonner vers les vallees de montagne.',
                'latitude' => 42.61667,
                'longitude' => 2.42189,
                'seoTitle' => 'Visiter Prades',
                'seoDescription' => 'Canigou, Conflent et idees de randonnées autour de Prades.',
            ],
            self::MONTNER_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Montner',
                'slug' => 'montner',
                'code' => '66116',
                'description' => 'Petit village viticole du Fenouilledes, utile pour tester les communes rurales et les repérages.',
                'latitude' => 42.74386,
                'longitude' => 2.68144,
                'seoTitle' => 'Visiter Montner',
                'seoDescription' => 'Village viticole, collines seches et balades autour de Montner.',
            ],
            self::PEYRESTORTES_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Peyrestortes',
                'slug' => 'peyrestortes',
                'code' => '66141',
                'description' => 'Commune proche de Perpignan, volontairement peu documentee pour tester les destinations sans contenu.',
                'latitude' => 42.75534,
                'longitude' => 2.85208,
                'seoTitle' => 'Peyrestortes',
                'seoDescription' => 'Commune proche de Perpignan utilisee pour les tests de pages destinations.',
            ],
            self::SAINT_LAURENT_SALANQUE_REFERENCE => [
                'parent' => $pyreneesOrientales,
                'name' => 'Saint-Laurent-de-la-Salanque',
                'slug' => 'saint-laurent-de-la-salanque',
                'code' => '66180',
                'description' => 'Commune de la Salanque entre etangs, marche et acces rapide au littoral.',
                'latitude' => 42.77285,
                'longitude' => 2.98983,
                'seoTitle' => 'Saint-Laurent-de-la-Salanque',
                'seoDescription' => 'Marche, etangs et idees autour de Saint-Laurent-de-la-Salanque.',
            ],
            self::CARCASSONNE_REFERENCE => [
                'parent' => $this->getReference(self::AUDE_REFERENCE, Destination::class),
                'name' => 'Carcassonne',
                'slug' => 'carcassonne',
                'code' => '11069',
                'description' => 'Ville fortifiee de l Aude, ajoutee pour tester les contenus hors Pyrénées-Orientales.',
                'latitude' => 43.21304,
                'longitude' => 2.34911,
                'seoTitle' => 'Visiter Carcassonne',
                'seoDescription' => 'Cite medievale, bastide et idees de visite a Carcassonne.',
            ],
            self::MONTPELLIER_REFERENCE => [
                'parent' => $this->getReference(self::HERAULT_REFERENCE, Destination::class),
                'name' => 'Montpellier',
                'slug' => 'montpellier',
                'code' => '34172',
                'description' => 'Grande ville de l Hérault, utile pour tester la recherche hors departement principal.',
                'latitude' => 43.61092,
                'longitude' => 3.87723,
                'seoTitle' => 'Visiter Montpellier',
                'seoDescription' => 'Centre historique, quartiers et idees de sorties a Montpellier.',
            ],
            self::TOULOUSE_REFERENCE => [
                'parent' => $this->getReference(self::HAUTE_GARONNE_REFERENCE, Destination::class),
                'name' => 'Toulouse',
                'slug' => 'toulouse',
                'code' => '31555',
                'description' => 'Ville rose et grande destination urbaine d Occitanie pour tester les communes homonymes et hors PO.',
                'latitude' => 43.60465,
                'longitude' => 1.44421,
                'seoTitle' => 'Visiter Toulouse',
                'seoDescription' => 'Patrimoine, Garonne, quartiers et idees de visites a Toulouse.',
            ],
        ];

        foreach ($cities as $reference => $data) {
            $city = $this->createDestination(
                name: $data['name'],
                slug: $data['slug'],
                type: DestinationType::City,
                parent: $data['parent'],
                code: $data['code'],
                description: $data['description'],
                latitude: $data['latitude'],
                longitude: $data['longitude'],
                seoTitle: $data['seoTitle'],
                seoDescription: $data['seoDescription'],
            );

            $manager->persist($city);
            $this->addReference($reference, $city);
        }

        $manager->flush();
    }

    private function createDestination(
        string $name,
        string $slug,
        DestinationType $type,
        ?Destination $parent = null,
        ?string $code = null,
        ?string $description = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $seoTitle = null,
        ?string $seoDescription = null,
    ): Destination {
        return (new Destination())
            ->setName($name)
            ->setSlug($slug)
            ->setType($type)
            ->setParent($parent)
            ->setCode($code)
            ->setDescription($description)
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setSeoTitle($seoTitle)
            ->setSeoDescription($seoDescription);
    }
}
