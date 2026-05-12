<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511090447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create quick hike and city visit terrain draft tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE city_visit_draft (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, google_maps_url LONGTEXT DEFAULT NULL, finished_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, destination_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_107D73FA989D9B62 (slug), INDEX IDX_107D73FA816C6140 (destination_id), INDEX IDX_107D73FAB03A8386 (created_by_id), INDEX idx_city_visit_draft_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city_visit_point (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(180) DEFAULT NULL, note LONGTEXT DEFAULT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, accuracy DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, created_at DATETIME NOT NULL, city_visit_draft_id INT NOT NULL, INDEX IDX_E1A4164AF4B41492 (city_visit_draft_id), INDEX idx_city_visit_point_type (type), INDEX idx_city_visit_point_position (position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_draft (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, finished_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, destination_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_HIKE_DRAFT_SLUG (slug), INDEX IDX_HIKE_DRAFT_CREATED_BY (created_by_id), INDEX IDX_HIKE_DRAFT_DESTINATION (destination_id), INDEX idx_hike_draft_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_point (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(180) DEFAULT NULL, note LONGTEXT DEFAULT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, accuracy DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, created_at DATETIME NOT NULL, hike_draft_id INT NOT NULL, INDEX IDX_HIKE_POINT_DRAFT (hike_draft_id), INDEX idx_hike_point_type (type), INDEX idx_hike_point_position (position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE city_visit_draft ADD CONSTRAINT FK_107D73FA816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE city_visit_draft ADD CONSTRAINT FK_107D73FAB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE city_visit_point ADD CONSTRAINT FK_E1A4164AF4B41492 FOREIGN KEY (city_visit_draft_id) REFERENCES city_visit_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hike_draft ADD CONSTRAINT FK_HIKE_DRAFT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE hike_draft ADD CONSTRAINT FK_HIKE_DRAFT_DESTINATION FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE hike_point ADD CONSTRAINT FK_HIKE_POINT_DRAFT FOREIGN KEY (hike_draft_id) REFERENCES hike_draft (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE city_visit_draft DROP FOREIGN KEY FK_107D73FA816C6140');
        $this->addSql('ALTER TABLE city_visit_draft DROP FOREIGN KEY FK_107D73FAB03A8386');
        $this->addSql('ALTER TABLE city_visit_point DROP FOREIGN KEY FK_E1A4164AF4B41492');
        $this->addSql('ALTER TABLE hike_draft DROP FOREIGN KEY FK_HIKE_DRAFT_CREATED_BY');
        $this->addSql('ALTER TABLE hike_draft DROP FOREIGN KEY FK_HIKE_DRAFT_DESTINATION');
        $this->addSql('ALTER TABLE hike_point DROP FOREIGN KEY FK_HIKE_POINT_DRAFT');
        $this->addSql('DROP TABLE city_visit_draft');
        $this->addSql('DROP TABLE city_visit_point');
        $this->addSql('DROP TABLE hike_draft');
        $this->addSql('DROP TABLE hike_point');
    }
}
