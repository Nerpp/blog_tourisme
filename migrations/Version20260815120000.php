<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le snapshot persistant et idempotent des publications Facebook.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Cette migration nécessite MySQL 8 ou supérieur.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE facebook_publication (
                id INT AUTO_INCREMENT NOT NULL,
                source_type VARCHAR(20) NOT NULL,
                source_id INT NOT NULL,
                message LONGTEXT NOT NULL,
                link VARCHAR(500) NOT NULL,
                status VARCHAR(20) NOT NULL,
                facebook_post_id VARCHAR(255) DEFAULT NULL,
                attempt_count INT NOT NULL,
                created_at DATETIME NOT NULL,
                first_attempt_at DATETIME DEFAULT NULL,
                last_attempt_at DATETIME DEFAULT NULL,
                last_dispatched_at DATETIME DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                processing_token VARCHAR(32) DEFAULT NULL,
                last_error LONGTEXT DEFAULT NULL,
                last_error_code VARCHAR(100) DEFAULT NULL,
                UNIQUE INDEX uniq_facebook_publication_source (source_type, source_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE facebook_publication');
    }
}
