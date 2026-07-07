<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Tag;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class TagFixtures extends Fixture implements FixtureGroupInterface
{
    use TestFixtureGroup;

    public const FAMILLE_REFERENCE = 'tag.famille';
    public const GRATUIT_REFERENCE = 'tag.gratuit';
    public const BORD_DE_MER_REFERENCE = 'tag.bord-de-mer';
    public const MONTAGNE_REFERENCE = 'tag.montagne';
    public const COUCHER_DE_SOLEIL_REFERENCE = 'tag.coucher-de-soleil';
    public const ACCESSIBLE_REFERENCE = 'tag.accessible';
    public const PHOTO_REFERENCE = 'tag.photo';
    public const INCONTOURNABLE_REFERENCE = 'tag.incontournable';
    public const PATRIMOINE_CATALAN_REFERENCE = 'tag.patrimoine-catalan';
    public const NATURE_REFERENCE = 'tag.nature';
    public const RANDONNEE_REFERENCE = 'tag.randonnee';
    public const PANORAMIQUE_REFERENCE = 'tag.panoramique';
    public const DEGREE_360_REFERENCE = 'tag.360';
    public const WEEK_END_REFERENCE = 'tag.week-end';

    public function load(ObjectManager $manager): void
    {
        $tags = [
            self::FAMILLE_REFERENCE => ['famille', 'famille'],
            self::GRATUIT_REFERENCE => ['gratuit', 'gratuit'],
            self::BORD_DE_MER_REFERENCE => ['bord de mer', 'bord-de-mer'],
            self::MONTAGNE_REFERENCE => ['montagne', 'montagne'],
            self::COUCHER_DE_SOLEIL_REFERENCE => ['coucher de soleil', 'coucher-de-soleil'],
            self::ACCESSIBLE_REFERENCE => ['accessible', 'accessible'],
            self::PHOTO_REFERENCE => ['photo', 'photo'],
            self::INCONTOURNABLE_REFERENCE => ['incontournable', 'incontournable'],
            self::PATRIMOINE_CATALAN_REFERENCE => ['patrimoine catalan', 'patrimoine-catalan'],
            self::NATURE_REFERENCE => ['nature', 'nature'],
            self::RANDONNEE_REFERENCE => ['randonnée', 'randonnee'],
            self::PANORAMIQUE_REFERENCE => ['panoramique', 'panoramique'],
            self::DEGREE_360_REFERENCE => ['360', '360'],
            self::WEEK_END_REFERENCE => ['week-end', 'week-end'],
        ];

        foreach ($tags as $reference => [$name, $slug]) {
            $tag = (new Tag())
                ->setName($name)
                ->setSlug($slug);

            $manager->persist($tag);
            $this->addReference($reference, $tag);
        }

        $manager->flush();
    }
}
