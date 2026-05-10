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
    public const COLLIOURE_REFERENCE = 'destination.collioure';
    public const ARGELES_SUR_MER_REFERENCE = 'destination.argeles-sur-mer';
    public const PERPIGNAN_REFERENCE = 'destination.perpignan';
    public const ILLE_SUR_TET_REFERENCE = 'destination.ille-sur-tet';
    public const FONT_ROMEU_REFERENCE = 'destination.font-romeu';

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
            slug: 'pyrenees-orientales',
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

        $cities = [
            self::COLLIOURE_REFERENCE => [
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
                'name' => 'Font-Romeu',
                'slug' => 'font-romeu',
                'code' => '66124',
                'description' => 'Station de montagne en Cerdagne, ideale pour la randonnee, les lacs d altitude et les activites de plein air.',
                'latitude' => 42.50564,
                'longitude' => 2.04147,
                'seoTitle' => 'Visiter Font-Romeu',
                'seoDescription' => 'Montagne, randonnees et grands paysages autour de Font-Romeu.',
            ],
        ];

        foreach ($cities as $reference => $data) {
            $city = $this->createDestination(
                name: $data['name'],
                slug: $data['slug'],
                type: DestinationType::City,
                parent: $pyreneesOrientales,
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
