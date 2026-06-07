<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607071239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the admin prevision destination notebook table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE prevision_destination (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, status VARCHAR(30) NOT NULL, source VARCHAR(30) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, country VARCHAR(120) DEFAULT NULL, region VARCHAR(150) DEFAULT NULL, department VARCHAR(150) DEFAULT NULL, commune VARCHAR(150) DEFAULT NULL, insee_code VARCHAR(20) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, gps_accuracy DOUBLE PRECISION DEFAULT NULL, priority VARCHAR(20) DEFAULT NULL, planned_period VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_prevision_destination_status (status), INDEX idx_prevision_destination_updated_at (updated_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE prevision_destination');
    }
}
