<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511185056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE city_visit_draft_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, city_visit_draft_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_city_visit_draft_media_city_visit_draft (city_visit_draft_id), INDEX idx_city_visit_draft_media_media_asset (media_asset_id), INDEX idx_city_visit_draft_media_role (role), INDEX idx_city_visit_draft_media_position (position), UNIQUE INDEX uniq_city_visit_draft_media_role (city_visit_draft_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_draft_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, hike_draft_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_hike_draft_media_hike_draft (hike_draft_id), INDEX idx_hike_draft_media_media_asset (media_asset_id), INDEX idx_hike_draft_media_role (role), INDEX idx_hike_draft_media_position (position), UNIQUE INDEX uniq_hike_draft_media_role (hike_draft_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE city_visit_draft_media ADD CONSTRAINT FK_6F1C36C1F4B41492 FOREIGN KEY (city_visit_draft_id) REFERENCES city_visit_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE city_visit_draft_media ADD CONSTRAINT FK_6F1C36C1ABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE hike_draft_media ADD CONSTRAINT FK_AAF872A807E537F FOREIGN KEY (hike_draft_id) REFERENCES hike_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hike_draft_media ADD CONSTRAINT FK_AAF872AABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE city_visit_draft_media DROP FOREIGN KEY FK_6F1C36C1F4B41492');
        $this->addSql('ALTER TABLE city_visit_draft_media DROP FOREIGN KEY FK_6F1C36C1ABB37F3');
        $this->addSql('ALTER TABLE hike_draft_media DROP FOREIGN KEY FK_AAF872A807E537F');
        $this->addSql('ALTER TABLE hike_draft_media DROP FOREIGN KEY FK_AAF872AABB37F3');
        $this->addSql('DROP TABLE city_visit_draft_media');
        $this->addSql('DROP TABLE hike_draft_media');
    }
}
