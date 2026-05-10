<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\ArticleDestination;
use App\Entity\ArticleMedia;
use App\Entity\ArticlePlace;
use App\Entity\ArticleTag;
use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\MediaRole;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class ArticleFixtures extends Fixture implements DependentFixtureInterface
{
    public const COLLIOURE_ONE_DAY_REFERENCE = 'article.que-faire-a-collioure-en-une-journee';
    public const BEST_PO_REFERENCE = 'article.plus-beaux-lieux-pyrenees-orientales';
    public const FORT_SAINT_ELME_REFERENCE = 'article.visiter-fort-saint-elme';
    public const PERPIGNAN_WEEKEND_DRAFT_REFERENCE = 'article.idees-week-end-perpignan';

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getUser(UserFixtures::ADMIN_REFERENCE);
        $basePublishedAt = new DateTimeImmutable('-10 days');

        $collioureOneDay = $this->createArticle(
            title: 'Que faire à Collioure en une journée ?',
            slug: 'que-faire-a-collioure-en-une-journee',
            category: $this->getCategory(CategoryFixtures::ITINERAIRE_REFERENCE),
            author: $admin,
            excerpt: 'Un itineraire simple pour profiter de Collioure entre patrimoine, plage, ruelles colorees et vues sur la cote Vermeille.',
            content: <<<TEXT
Collioure se visite tres bien en une journee si l on accepte de prendre son temps. Le matin, commencez par le front de mer lorsque la lumiere glisse encore doucement sur les facades colorees. Le quartier autour de la plage de Boramar donne tout de suite le ton : barques, clocher, galets et terrasses animees.

Poursuivez avec le Château Royal, dont les remparts offrent une lecture immediate de la baie. La visite est assez courte pour s inserer dans une journee dense, mais assez riche pour comprendre l importance strategique de Collioure sur la cote Vermeille.

L apres-midi, montez vers le Fort Saint-Elme. La marche demande un peu d energie, mais la recompense est nette : le panorama embrasse Collioure, Port-Vendres, les vignes et les premiers reliefs. En redescendant, gardez du temps pour une derniere pause sur la plage de Boramar au coucher du soleil.
TEXT,
            status: ContentStatus::Published,
            featuredImage: $this->getMedia(MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE),
            seoTitle: 'Que faire à Collioure en une journée ?',
            seoDescription: 'Itinéraire pour visiter Collioure en une journée : Château Royal, Fort Saint-Elme, plage de Boramar et conseils pratiques.',
            publishedAt: $basePublishedAt,
        );
        $manager->persist($collioureOneDay);
        $this->addReference(self::COLLIOURE_ONE_DAY_REFERENCE, $collioureOneDay);
        $this->linkDestinations($manager, $collioureOneDay, [
            [DestinationFixtures::COLLIOURE_REFERENCE, 0],
        ]);
        $this->linkPlaces($manager, $collioureOneDay, [
            [PlaceFixtures::FORT_SAINT_ELME_REFERENCE, 0],
            [PlaceFixtures::CHATEAU_ROYAL_COLLIOURE_REFERENCE, 1],
            [PlaceFixtures::PLAGE_BORAMAR_REFERENCE, 2],
        ]);
        $this->linkTags($manager, $collioureOneDay, [
            TagFixtures::WEEK_END_REFERENCE,
            TagFixtures::BORD_DE_MER_REFERENCE,
            TagFixtures::PATRIMOINE_CATALAN_REFERENCE,
            TagFixtures::INCONTOURNABLE_REFERENCE,
        ]);
        $this->linkMedia($manager, $collioureOneDay, [
            [MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Gallery, 1],
            [MediaAssetFixtures::COLLIOURE_VIDEO_REFERENCE, MediaRole::Content, 2],
            [MediaAssetFixtures::COLLIOURE_STANDARD_REFERENCE, MediaRole::Seo, 3],
        ]);

        $bestPlaces = $this->createArticle(
            title: 'Les plus beaux lieux des Pyrénées-Orientales',
            slug: 'les-plus-beaux-lieux-des-pyrenees-orientales',
            category: $this->getCategory(CategoryFixtures::CONSEIL_VOYAGE_REFERENCE),
            author: $admin,
            excerpt: 'Une selection de sites contrastes pour decouvrir la diversite des Pyrénées-Orientales, de la mer a la montagne.',
            content: <<<TEXT
Les Pyrénées-Orientales concentrent des paysages tres differents sur un territoire relativement compact. En quelques jours, il est possible de passer de la cote Vermeille aux reliefs de Cerdagne, avec des haltes naturelles et patrimoniales tres variees.

Autour de Collioure, le Fort Saint-Elme offre l un des plus beaux panoramas du departement. Plus a l interieur, les Orgues d’Ille-sur-Têt surprennent par leur decor mineral, presque lunaire, facile a integrer dans une journee depuis Perpignan.

Pour changer completement d ambiance, prenez la direction du lac des Bouillouses. L altitude, les pins et les sentiers donnent une impression de grand air immediate. Cette diversite explique pourquoi le departement fonctionne aussi bien pour un week-end que pour un sejour plus long.
TEXT,
            status: ContentStatus::Published,
            featuredImage: $this->getMedia(MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE),
            seoTitle: 'Les plus beaux lieux des Pyrénées-Orientales',
            seoDescription: 'Selection de sites incontournables dans les Pyrénées-Orientales : Collioure, Orgues d Ille-sur-Têt et lac des Bouillouses.',
            publishedAt: $basePublishedAt->modify('+2 days'),
        );
        $manager->persist($bestPlaces);
        $this->addReference(self::BEST_PO_REFERENCE, $bestPlaces);
        $this->linkDestinations($manager, $bestPlaces, [
            [DestinationFixtures::PYRENEES_ORIENTALES_REFERENCE, 0],
        ]);
        $this->linkPlaces($manager, $bestPlaces, [
            [PlaceFixtures::FORT_SAINT_ELME_REFERENCE, 0],
            [PlaceFixtures::ORGUES_ILLE_SUR_TET_REFERENCE, 1],
            [PlaceFixtures::LAC_BOUILLOUSES_REFERENCE, 2],
        ]);
        $this->linkTags($manager, $bestPlaces, [
            TagFixtures::NATURE_REFERENCE,
            TagFixtures::PHOTO_REFERENCE,
            TagFixtures::PANORAMIQUE_REFERENCE,
        ]);
        $this->linkMedia($manager, $bestPlaces, [
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::COTE_VERMEILLE_180_REFERENCE, MediaRole::Gallery, 1],
            [MediaAssetFixtures::PORT_COLLIOURE_WIDE_REFERENCE, MediaRole::Content, 2],
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Seo, 3],
        ]);

        $fortArticle = $this->createArticle(
            title: 'Visiter le Fort Saint-Elme',
            slug: 'visiter-le-fort-saint-elme',
            category: $this->getCategory(CategoryFixtures::PATRIMOINE_REFERENCE),
            author: $admin,
            excerpt: 'Histoire, panorama et conseils pratiques pour visiter le Fort Saint-Elme, au-dessus de Collioure.',
            content: <<<TEXT
Le Fort Saint-Elme est l une des visites les plus marquantes autour de Collioure. Sa position en hauteur permet de saisir d un seul regard la logique du site : un village portuaire protege, des routes maritimes a surveiller et des reliefs qui plongent vers la Mediterranee.

La visite interieure complete bien le panorama. Elle explique la fonction defensive du fort, ses transformations et son role dans l histoire locale. Les amateurs de photo apprecieront particulierement les perspectives sur les vignes, les remparts et le littoral.

Pour une experience plus confortable, privilegiez le matin ou la fin de journee. La montee peut etre chaude en ete, mais elle reste l une des meilleures facons de comprendre la relation entre Collioure et la cote Vermeille.
TEXT,
            status: ContentStatus::Published,
            featuredImage: $this->getMedia(MediaAssetFixtures::FORT_SAINT_ELME_360_REFERENCE),
            seoTitle: 'Visiter le Fort Saint-Elme a Collioure',
            seoDescription: 'Guide de visite du Fort Saint-Elme : acces, duree, points de vue et patrimoine catalan.',
            publishedAt: $basePublishedAt->modify('+4 days'),
        );
        $manager->persist($fortArticle);
        $this->addReference(self::FORT_SAINT_ELME_REFERENCE, $fortArticle);
        $this->linkDestinations($manager, $fortArticle, [
            [DestinationFixtures::COLLIOURE_REFERENCE, 0],
        ]);
        $this->linkPlaces($manager, $fortArticle, [
            [PlaceFixtures::FORT_SAINT_ELME_REFERENCE, 0],
        ]);
        $this->linkTags($manager, $fortArticle, [
            TagFixtures::PATRIMOINE_CATALAN_REFERENCE,
            TagFixtures::DEGREE_360_REFERENCE,
            TagFixtures::PANORAMIQUE_REFERENCE,
        ]);
        $this->linkMedia($manager, $fortArticle, [
            [MediaAssetFixtures::FORT_SAINT_ELME_360_REFERENCE, MediaRole::Cover, 0],
            [MediaAssetFixtures::FORT_SAINT_ELME_360_REFERENCE, MediaRole::Content, 1],
            [MediaAssetFixtures::COLLIOURE_PANORAMA_REFERENCE, MediaRole::Gallery, 2],
            [MediaAssetFixtures::FORT_SAINT_ELME_360_REFERENCE, MediaRole::Seo, 3],
        ]);

        $draft = $this->createArticle(
            title: 'Idées de week-end autour de Perpignan',
            slug: 'idees-de-week-end-autour-de-perpignan',
            category: $this->getCategory(CategoryFixtures::CONSEIL_VOYAGE_REFERENCE),
            author: $admin,
            excerpt: 'Brouillon pour preparer des suggestions de courts sejours autour de Perpignan.',
            content: <<<TEXT
Ce brouillon servira a organiser plusieurs idees de week-end depuis Perpignan : cote Vermeille, vallee de la Tet, villages catalans et premieres randonnees vers la montagne.

Les sections restent a completer avec des temps de trajet, des conseils saisonniers et des suggestions d adresses locales.
TEXT,
            status: ContentStatus::Draft,
            featuredImage: $this->getMedia(MediaAssetFixtures::PORT_COLLIOURE_WIDE_REFERENCE),
            seoTitle: 'Idées de week-end autour de Perpignan',
            seoDescription: 'Brouillon de conseils pour organiser un week-end autour de Perpignan.',
        );
        $manager->persist($draft);
        $this->addReference(self::PERPIGNAN_WEEKEND_DRAFT_REFERENCE, $draft);
        $this->linkDestinations($manager, $draft, [
            [DestinationFixtures::PERPIGNAN_REFERENCE, 0],
        ]);
        $this->linkTags($manager, $draft, [
            TagFixtures::WEEK_END_REFERENCE,
            TagFixtures::BORD_DE_MER_REFERENCE,
            TagFixtures::NATURE_REFERENCE,
        ]);
        $this->linkMedia($manager, $draft, [
            [MediaAssetFixtures::PORT_COLLIOURE_WIDE_REFERENCE, MediaRole::Cover, 0],
        ]);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            DestinationFixtures::class,
            CategoryFixtures::class,
            TagFixtures::class,
            MediaAssetFixtures::class,
            PlaceFixtures::class,
        ];
    }

    private function createArticle(
        string $title,
        string $slug,
        Category $category,
        User $author,
        string $excerpt,
        string $content,
        ContentStatus $status,
        MediaAsset $featuredImage,
        string $seoTitle,
        string $seoDescription,
        ?DateTimeImmutable $publishedAt = null,
    ): Article {
        return (new Article())
            ->setTitle($title)
            ->setSlug($slug)
            ->setCategory($category)
            ->setAuthor($author)
            ->setExcerpt($excerpt)
            ->setContent($content)
            ->setStatus($status)
            ->setFeaturedImage($featuredImage)
            ->setSeoTitle($seoTitle)
            ->setSeoDescription($seoDescription)
            ->setPublishedAt($publishedAt);
    }

    /**
     * @param list<array{0: string, 1: int}> $destinationLinks
     */
    private function linkDestinations(ObjectManager $manager, Article $article, array $destinationLinks): void
    {
        foreach ($destinationLinks as [$destinationReference, $position]) {
            $articleDestination = (new ArticleDestination())
                ->setArticle($article)
                ->setDestination($this->getDestination($destinationReference))
                ->setPosition($position);

            $manager->persist($articleDestination);
        }
    }

    /**
     * @param list<array{0: string, 1: int}> $placeLinks
     */
    private function linkPlaces(ObjectManager $manager, Article $article, array $placeLinks): void
    {
        foreach ($placeLinks as [$placeReference, $position]) {
            $articlePlace = (new ArticlePlace())
                ->setArticle($article)
                ->setPlace($this->getPlace($placeReference))
                ->setPosition($position);

            $manager->persist($articlePlace);
        }
    }

    /**
     * @param list<string> $tagReferences
     */
    private function linkTags(ObjectManager $manager, Article $article, array $tagReferences): void
    {
        foreach ($tagReferences as $tagReference) {
            $articleTag = (new ArticleTag())
                ->setArticle($article)
                ->setTag($this->getTag($tagReference));

            $manager->persist($articleTag);
        }
    }

    /**
     * @param list<array{0: string, 1: MediaRole, 2: int}> $mediaLinks
     */
    private function linkMedia(ObjectManager $manager, Article $article, array $mediaLinks): void
    {
        foreach ($mediaLinks as [$mediaReference, $role, $position]) {
            $articleMedia = (new ArticleMedia())
                ->setArticle($article)
                ->setMediaAsset($this->getMedia($mediaReference))
                ->setRole($role)
                ->setPosition($position);

            $manager->persist($articleMedia);
        }
    }

    private function getUser(string $reference): User
    {
        return $this->getReference($reference, User::class);
    }

    private function getCategory(string $reference): Category
    {
        return $this->getReference($reference, Category::class);
    }

    private function getDestination(string $reference): Destination
    {
        return $this->getReference($reference, Destination::class);
    }

    private function getPlace(string $reference): Place
    {
        return $this->getReference($reference, Place::class);
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
