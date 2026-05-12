<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create point media links for Studio hike and city visit drafts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE city_visit_point_media (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, point_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_city_visit_point_media_point (point_id), INDEX idx_city_visit_point_media_media_asset (media_asset_id), UNIQUE INDEX uniq_city_visit_point_media (point_id, media_asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_point_media (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, point_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_hike_point_media_point (point_id), INDEX idx_hike_point_media_media_asset (media_asset_id), UNIQUE INDEX uniq_hike_point_media (point_id, media_asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE city_visit_point_media ADD CONSTRAINT FK_CB5E327DC028CEA2 FOREIGN KEY (point_id) REFERENCES city_visit_point (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE city_visit_point_media ADD CONSTRAINT FK_CB5E327DABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE hike_point_media ADD CONSTRAINT FK_AEED8396C028CEA2 FOREIGN KEY (point_id) REFERENCES hike_point (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hike_point_media ADD CONSTRAINT FK_AEED8396ABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE city_visit_point_media DROP FOREIGN KEY FK_CB5E327DC028CEA2');
        $this->addSql('ALTER TABLE city_visit_point_media DROP FOREIGN KEY FK_CB5E327DABB37F3');
        $this->addSql('ALTER TABLE hike_point_media DROP FOREIGN KEY FK_AEED8396C028CEA2');
        $this->addSql('ALTER TABLE hike_point_media DROP FOREIGN KEY FK_AEED8396ABB37F3');
        $this->addSql('DROP TABLE city_visit_point_media');
        $this->addSql('DROP TABLE hike_point_media');
    }
}
