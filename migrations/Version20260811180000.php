<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le stockage partagé Symfony Lock utilisé par le WebCron Instagram.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Migration utilisable uniquement sur MySQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lock_keys (
                key_id VARCHAR(64) NOT NULL,
                key_token VARCHAR(44) NOT NULL,
                key_expiration INT UNSIGNED NOT NULL,
                PRIMARY KEY (key_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lock_keys');
    }
}
