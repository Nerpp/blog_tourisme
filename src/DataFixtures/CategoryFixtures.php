<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Enum\CategoryType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class CategoryFixtures extends Fixture
{
    public const MONUMENT_REFERENCE = 'category.monument';
    public const PLAGE_REFERENCE = 'category.plage';
    public const RANDONNEE_REFERENCE = 'category.randonnee';
    public const VILLAGE_REFERENCE = 'category.village';
    public const POINT_DE_VUE_REFERENCE = 'category.point-de-vue';
    public const PATRIMOINE_REFERENCE = 'category.patrimoine';
    public const HISTOIRE_LOCALE_REFERENCE = 'category.histoire-locale';
    public const LEGENDES_TRADITIONS_REFERENCE = 'category.legendes-traditions';
    public const NATURE_REFERENCE = 'category.nature';
    public const CULTURE_REFERENCE = 'category.culture';
    public const INFOS_PRATIQUES_REFERENCE = 'category.infos-pratiques';
    public const MUSEE_REFERENCE = 'category.musee';
    public const RESTAURANT_REFERENCE = 'category.restaurant';
    public const ITINERAIRE_REFERENCE = 'category.itineraire';
    public const CONSEIL_VOYAGE_REFERENCE = 'category.conseil-voyage';

    public function load(ObjectManager $manager): void
    {
        $categories = [
            self::MONUMENT_REFERENCE => ['Monument', 'monument', CategoryType::Place, 'Chateaux, forts, edifices religieux et sites historiques a visiter.'],
            self::PLAGE_REFERENCE => ['Plage', 'plage', CategoryType::Place, 'Plages, criques et coins de baignade pour profiter du littoral.'],
            self::RANDONNEE_REFERENCE => ['Randonnée', 'randonnee', CategoryType::Place, 'Sentiers, balades et itineraires de marche.'],
            self::VILLAGE_REFERENCE => ['Village', 'village', CategoryType::Place, 'Villages de caractere, ruelles et lieux de vie locaux.'],
            self::POINT_DE_VUE_REFERENCE => ['Point de vue', 'point-de-vue', CategoryType::Place, 'Belvederes et panoramas pour admirer les paysages.'],
            self::PATRIMOINE_REFERENCE => ['Patrimoine', 'patrimoine', CategoryType::Both, 'Sites et articles consacres a l histoire, aux traditions et a la culture locale.'],
            self::HISTOIRE_LOCALE_REFERENCE => ['Histoire locale', 'histoire-locale', CategoryType::Article, 'Articles consacres aux origines, personnages et evenements locaux.'],
            self::LEGENDES_TRADITIONS_REFERENCE => ['Légendes et traditions', 'legendes-et-traditions', CategoryType::Article, 'Recits, legendes, memoires orales et traditions locales.'],
            self::NATURE_REFERENCE => ['Nature', 'nature', CategoryType::Both, 'Espaces naturels, lacs, reliefs et paysages remarquables.'],
            self::CULTURE_REFERENCE => ['Culture', 'culture', CategoryType::Article, 'Arts, patrimoines vivants, pratiques culturelles et decouvertes locales.'],
            self::INFOS_PRATIQUES_REFERENCE => ['Infos pratiques', 'infos-pratiques', CategoryType::Article, 'Informations utiles pour preparer une visite, une balade ou une sortie.'],
            self::MUSEE_REFERENCE => ['Musée', 'musee', CategoryType::Place, 'Musees et espaces d interpretation.'],
            self::RESTAURANT_REFERENCE => ['Restaurant', 'restaurant', CategoryType::Place, 'Tables, pauses gourmandes et adresses locales.'],
            self::ITINERAIRE_REFERENCE => ['Itinéraire', 'itineraire', CategoryType::Article, 'Parcours organises pour visiter une destination en quelques heures ou plusieurs jours.'],
            self::CONSEIL_VOYAGE_REFERENCE => ['Conseil voyage', 'conseil-voyage', CategoryType::Article, 'Conseils pratiques pour preparer un sejour, choisir une periode ou organiser ses visites.'],
        ];

        foreach ($categories as $reference => [$name, $slug, $type, $description]) {
            $category = (new Category())
                ->setName($name)
                ->setSlug($slug)
                ->setType($type)
                ->setDescription($description);

            $manager->persist($category);
            $this->addReference($reference, $category);
        }

        $manager->flush();
    }
}
