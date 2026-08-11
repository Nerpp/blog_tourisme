<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persiste les publications Instagram idempotentes, leurs lots et médias, protège l’historique existant et crée le transport Messenger.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Cette migration nécessite MySQL 8 ou supérieur.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE instagram_publication (
                id INT AUTO_INCREMENT NOT NULL,
                source_type VARCHAR(20) NOT NULL,
                source_id INT NOT NULL,
                status VARCHAR(30) NOT NULL,
                caption LONGTEXT DEFAULT NULL,
                total_media_count INT NOT NULL,
                published_media_count INT NOT NULL,
                total_batch_count INT NOT NULL,
                published_batch_count INT NOT NULL,
                attempt_count INT NOT NULL,
                first_attempt_at DATETIME DEFAULT NULL,
                last_attempt_at DATETIME DEFAULT NULL,
                last_dispatched_at DATETIME DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                processing_token VARCHAR(32) DEFAULT NULL,
                last_error LONGTEXT DEFAULT NULL,
                last_error_code VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_instagram_publication_status (status),
                INDEX idx_instagram_publication_dispatch (status, last_dispatched_at),
                INDEX idx_instagram_publication_last_attempt (last_attempt_at),
                UNIQUE INDEX uniq_instagram_publication_source (source_type, source_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE instagram_publication_batch (
                id INT AUTO_INCREMENT NOT NULL,
                position INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                caption LONGTEXT DEFAULT NULL,
                media_count INT NOT NULL,
                attempt_count INT NOT NULL,
                first_attempt_at DATETIME DEFAULT NULL,
                last_attempt_at DATETIME DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                container_id VARCHAR(255) DEFAULT NULL,
                instagram_media_id VARCHAR(255) DEFAULT NULL,
                last_error LONGTEXT DEFAULT NULL,
                last_error_code VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                publication_id INT NOT NULL,
                INDEX IDX_F6AA43C038B217A7 (publication_id),
                INDEX idx_instagram_publication_batch_status (status),
                INDEX idx_instagram_publication_batch_publication_status (publication_id, status),
                UNIQUE INDEX uniq_instagram_publication_batch_position (publication_id, position),
                PRIMARY KEY (id),
                CONSTRAINT FK_F6AA43C038B217A7 FOREIGN KEY (publication_id) REFERENCES instagram_publication (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE instagram_publication_media (
                id INT AUTO_INCREMENT NOT NULL,
                source_media_id INT DEFAULT NULL,
                position INT NOT NULL,
                batch_position INT NOT NULL,
                public_url LONGTEXT NOT NULL,
                caption LONGTEXT DEFAULT NULL,
                container_id VARCHAR(255) DEFAULT NULL,
                container_created_at DATETIME DEFAULT NULL,
                attempt_count INT NOT NULL,
                last_attempt_at DATETIME DEFAULT NULL,
                last_error LONGTEXT DEFAULT NULL,
                last_error_code VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                publication_id INT NOT NULL,
                batch_id INT NOT NULL,
                media_asset_id INT DEFAULT NULL,
                INDEX IDX_648DB01838B217A7 (publication_id),
                INDEX IDX_648DB018F39EBE7A (batch_id),
                INDEX idx_instagram_publication_media_asset (media_asset_id),
                INDEX idx_instagram_publication_media_container (container_id),
                UNIQUE INDEX uniq_instagram_publication_media_position (publication_id, position),
                UNIQUE INDEX uniq_instagram_batch_media_position (batch_id, batch_position),
                PRIMARY KEY (id),
                CONSTRAINT FK_648DB01838B217A7 FOREIGN KEY (publication_id) REFERENCES instagram_publication (id) ON DELETE CASCADE,
                CONSTRAINT FK_648DB018F39EBE7A FOREIGN KEY (batch_id) REFERENCES instagram_publication_batch (id) ON DELETE CASCADE,
                CONSTRAINT FK_648DB018ABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT NOT NULL,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        // Les contenus déjà publics et ceux ayant déjà déclenché la notification
        // éditoriale sont neutralisés. L’historique incomplet ne permet pas de
        // prouver qu’une ancienne publication Instagram n’a jamais eu lieu.
        $this->addSql(<<<'SQL'
            INSERT INTO instagram_publication (
                source_type,
                source_id,
                status,
                total_media_count,
                published_media_count,
                total_batch_count,
                published_batch_count,
                attempt_count,
                created_at,
                updated_at
            )
            SELECT
                legacy.source_type,
                legacy.source_id,
                'legacy_skipped',
                0,
                0,
                0,
                0,
                0,
                MIN(legacy.legacy_at),
                MIN(legacy.legacy_at)
            FROM (
                SELECT
                    'hike' AS source_type,
                    hike.id AS source_id,
                    COALESCE(hike.finished_at, hike.updated_at, hike.created_at, CURRENT_TIMESTAMP) AS legacy_at
                FROM hike_draft AS hike
                WHERE hike.status IN ('finished', 'converted')

                UNION ALL

                SELECT
                    'city_visit' AS source_type,
                    city_visit.id AS source_id,
                    COALESCE(city_visit.finished_at, city_visit.updated_at, city_visit.created_at, CURRENT_TIMESTAMP) AS legacy_at
                FROM city_visit_draft AS city_visit
                WHERE city_visit.status IN ('finished', 'converted')

                UNION ALL

                SELECT
                    'hike' AS source_type,
                    notification.content_id AS source_id,
                    COALESCE(notification.sent_at, notification.created_at, CURRENT_TIMESTAMP) AS legacy_at
                FROM publication_notification_log AS notification
                INNER JOIN hike_draft AS hike ON hike.id = notification.content_id
                WHERE notification.content_type = 'hike'

                UNION ALL

                SELECT
                    'city_visit' AS source_type,
                    notification.content_id AS source_id,
                    COALESCE(notification.sent_at, notification.created_at, CURRENT_TIMESTAMP) AS legacy_at
                FROM publication_notification_log AS notification
                INNER JOIN city_visit_draft AS city_visit ON city_visit.id = notification.content_id
                WHERE notification.content_type = 'city_visit'
            ) AS legacy
            GROUP BY legacy.source_type, legacy.source_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'La rétrogradation supprimerait l’historique Instagram et pourrait réautoriser des republications historiques.',
        );
    }
}
