<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise et sécurise l’ordre des étapes, autorise un GPS vide et mémorise les coordonnées héritées.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Cette migration nécessite MySQL 8 ou supérieur.',
        );

        // The id is used only once to preserve the historical order when old
        // rows share a position. Runtime ordering is exclusively position ASC.
        $this->addSql(<<<'SQL'
            UPDATE hike_point AS point
            INNER JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY hike_draft_id ORDER BY position ASC, id ASC) AS normalized_position
                FROM hike_point
            ) AS ranked ON ranked.id = point.id
            SET point.position = ranked.normalized_position
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE city_visit_point AS point
            INNER JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY city_visit_draft_id ORDER BY position ASC, id ASC) AS normalized_position
                FROM city_visit_point
            ) AS ranked ON ranked.id = point.id
            SET point.position = ranked.normalized_position
            SQL);

        $this->addSql('ALTER TABLE hike_point MODIFY latitude DOUBLE PRECISION DEFAULT NULL, MODIFY longitude DOUBLE PRECISION DEFAULT NULL, ADD coordinates_inherited TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE city_visit_point MODIFY latitude DOUBLE PRECISION DEFAULT NULL, MODIFY longitude DOUBLE PRECISION DEFAULT NULL, ADD coordinates_inherited TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_hike_point_draft_position ON hike_point (hike_draft_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_city_visit_point_draft_position ON city_visit_point (city_visit_draft_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Cette migration nécessite MySQL.',
        );

        $emptyHikeCoordinates = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM hike_point WHERE latitude IS NULL OR longitude IS NULL');
        $emptyCityCoordinates = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM city_visit_point WHERE latitude IS NULL OR longitude IS NULL');
        if ($emptyHikeCoordinates > 0 || $emptyCityCoordinates > 0) {
            $this->throwIrreversibleMigrationException(
                'Des étapes sans coordonnées existent ; la rétrogradation ne doit pas inventer de coordonnées GPS.',
            );
        }

        $this->addSql('DROP INDEX uniq_hike_point_draft_position ON hike_point');
        $this->addSql('DROP INDEX uniq_city_visit_point_draft_position ON city_visit_point');
        $this->addSql('ALTER TABLE hike_point MODIFY latitude DOUBLE PRECISION NOT NULL, MODIFY longitude DOUBLE PRECISION NOT NULL, DROP coordinates_inherited');
        $this->addSql('ALTER TABLE city_visit_point MODIFY latitude DOUBLE PRECISION NOT NULL, MODIFY longitude DOUBLE PRECISION NOT NULL, DROP coordinates_inherited');
    }
}
