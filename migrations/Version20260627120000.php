<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627120000 extends AbstractMigration
{
    /** @var list<array{name: string, slug: string, type: string, description: string}> */
    private const ARTICLE_CATEGORIES = [
        ['name' => 'Patrimoine', 'slug' => 'patrimoine', 'type' => 'both', 'description' => 'Sites et articles consacres a l histoire, aux traditions et a la culture locale.'],
        ['name' => 'Histoire locale', 'slug' => 'histoire-locale', 'type' => 'article', 'description' => 'Articles consacres aux origines, personnages et evenements locaux.'],
        ['name' => 'Légendes et traditions', 'slug' => 'legendes-et-traditions', 'type' => 'article', 'description' => 'Recits, legendes, memoires orales et traditions locales.'],
        ['name' => 'Nature', 'slug' => 'nature', 'type' => 'both', 'description' => 'Espaces naturels, lacs, reliefs et paysages remarquables.'],
        ['name' => 'Culture', 'slug' => 'culture', 'type' => 'article', 'description' => 'Arts, patrimoines vivants, pratiques culturelles et decouvertes locales.'],
        ['name' => 'Infos pratiques', 'slug' => 'infos-pratiques', 'type' => 'article', 'description' => 'Informations utiles pour preparer une visite, une balade ou une sortie.'],
        ['name' => 'Itinéraire', 'slug' => 'itineraire', 'type' => 'article', 'description' => 'Parcours organises pour visiter une destination en quelques heures ou plusieurs jours.'],
        ['name' => 'Conseil voyage', 'slug' => 'conseil-voyage', 'type' => 'article', 'description' => 'Conseils pratiques pour preparer un sejour, choisir une periode ou organiser ses visites.'],
    ];

    public function getDescription(): string
    {
        return 'Add only missing article categories from the existing fixture definitions.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ARTICLE_CATEGORIES as $category) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO category (name, slug, type, description, created_at, updated_at)
                    SELECT :name, :slug, :type, :description, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    WHERE NOT EXISTS (SELECT 1 FROM category existing_category WHERE existing_category.slug = :existing_slug)
                    SQL,
                $category + ['existing_slug' => $category['slug']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Intentionally irreversible: categories may have been customized or used after insertion.
    }
}
