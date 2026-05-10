<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510074718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tourism blog content, destination, place, media, and tagging schema.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, excerpt LONGTEXT DEFAULT NULL, content LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, seo_title VARCHAR(180) DEFAULT NULL, seo_description VARCHAR(255) DEFAULT NULL, canonical_url VARCHAR(500) DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, category_id INT DEFAULT NULL, featured_image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_23A0E66989D9B62 (slug), INDEX IDX_23A0E663569D950 (featured_image_id), INDEX idx_article_status (status), INDEX idx_article_published_at (published_at), INDEX idx_article_author (author_id), INDEX idx_article_category (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_destination (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, destination_id INT NOT NULL, INDEX idx_article_destination_article (article_id), INDEX idx_article_destination_destination (destination_id), INDEX idx_article_destination_position (position), UNIQUE INDEX uniq_article_destination (article_id, destination_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_article_media_article (article_id), INDEX idx_article_media_media_asset (media_asset_id), INDEX idx_article_media_role (role), INDEX idx_article_media_position (position), UNIQUE INDEX uniq_article_media_role (article_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_place (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, place_id INT NOT NULL, INDEX idx_article_place_article (article_id), INDEX idx_article_place_place (place_id), INDEX idx_article_place_position (position), UNIQUE INDEX uniq_article_place (article_id, place_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_tag (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, tag_id INT NOT NULL, INDEX idx_article_tag_article (article_id), INDEX idx_article_tag_tag (tag_id), UNIQUE INDEX uniq_article_tag (article_id, tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(180) NOT NULL, type VARCHAR(20) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_64C19C1989D9B62 (slug), INDEX idx_category_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE destination (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(180) NOT NULL, type VARCHAR(20) NOT NULL, code VARCHAR(40) DEFAULT NULL, description LONGTEXT DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, seo_title VARCHAR(180) DEFAULT NULL, seo_description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3EC63EAA989D9B62 (slug), INDEX idx_destination_type (type), INDEX idx_destination_parent (parent_id), INDEX idx_destination_coordinates (latitude, longitude), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_asset (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) DEFAULT NULL, alt_text VARCHAR(255) DEFAULT NULL, caption LONGTEXT DEFAULT NULL, media_type VARCHAR(20) NOT NULL, image_type VARCHAR(20) DEFAULT NULL, video_type VARCHAR(20) DEFAULT NULL, file_path VARCHAR(255) DEFAULT NULL, thumbnail_path VARCHAR(255) DEFAULT NULL, external_url VARCHAR(500) DEFAULT NULL, mime_type VARCHAR(120) DEFAULT NULL, file_size BIGINT DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, duration_seconds INT DEFAULT NULL, projection VARCHAR(80) DEFAULT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX idx_media_asset_media_type (media_type), INDEX idx_media_asset_image_type (image_type), INDEX idx_media_asset_video_type (video_type), INDEX idx_media_asset_uploaded_by (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, short_description LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, visit_duration_minutes INT DEFAULT NULL, difficulty VARCHAR(20) DEFAULT NULL, price_type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, seo_title VARCHAR(180) DEFAULT NULL, seo_description VARCHAR(255) DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, destination_id INT NOT NULL, category_id INT DEFAULT NULL, featured_image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_741D53CD989D9B62 (slug), INDEX IDX_741D53CD3569D950 (featured_image_id), INDEX idx_place_status (status), INDEX idx_place_published_at (published_at), INDEX idx_place_destination (destination_id), INDEX idx_place_category (category_id), INDEX idx_place_coordinates (latitude, longitude), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, place_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_place_media_place (place_id), INDEX idx_place_media_media_asset (media_asset_id), INDEX idx_place_media_role (role), INDEX idx_place_media_position (position), UNIQUE INDEX uniq_place_media_role (place_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place_tag (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, place_id INT NOT NULL, tag_id INT NOT NULL, INDEX idx_place_tag_place (place_id), INDEX idx_place_tag_tag (tag_id), UNIQUE INDEX uniq_place_tag (place_id, tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_389B783989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66F675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E6612469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E663569D950 FOREIGN KEY (featured_image_id) REFERENCES media_asset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE article_destination ADD CONSTRAINT FK_A44554CD7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_destination ADD CONSTRAINT FK_A44554CD816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE article_media ADD CONSTRAINT FK_1D9BD31D7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_media ADD CONSTRAINT FK_1D9BD31DABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE article_place ADD CONSTRAINT FK_3AA21DC7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_place ADD CONSTRAINT FK_3AA21DCDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE article_tag ADD CONSTRAINT FK_919694F97294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_tag ADD CONSTRAINT FK_919694F9BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE destination ADD CONSTRAINT FK_3EC63EAA727ACA70 FOREIGN KEY (parent_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media_asset ADD CONSTRAINT FK_1DB69EEDA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD3569D950 FOREIGN KEY (featured_image_id) REFERENCES media_asset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE place_media ADD CONSTRAINT FK_C35ABA2FDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE place_media ADD CONSTRAINT FK_C35ABA2FABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE place_tag ADD CONSTRAINT FK_C3BD172DA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE place_tag ADD CONSTRAINT FK_C3BD172BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66F675F31B');
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E6612469DE2');
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E663569D950');
        $this->addSql('ALTER TABLE article_destination DROP FOREIGN KEY FK_A44554CD7294869C');
        $this->addSql('ALTER TABLE article_destination DROP FOREIGN KEY FK_A44554CD816C6140');
        $this->addSql('ALTER TABLE article_media DROP FOREIGN KEY FK_1D9BD31D7294869C');
        $this->addSql('ALTER TABLE article_media DROP FOREIGN KEY FK_1D9BD31DABB37F3');
        $this->addSql('ALTER TABLE article_place DROP FOREIGN KEY FK_3AA21DC7294869C');
        $this->addSql('ALTER TABLE article_place DROP FOREIGN KEY FK_3AA21DCDA6A219');
        $this->addSql('ALTER TABLE article_tag DROP FOREIGN KEY FK_919694F97294869C');
        $this->addSql('ALTER TABLE article_tag DROP FOREIGN KEY FK_919694F9BAD26311');
        $this->addSql('ALTER TABLE destination DROP FOREIGN KEY FK_3EC63EAA727ACA70');
        $this->addSql('ALTER TABLE media_asset DROP FOREIGN KEY FK_1DB69EEDA2B28FE8');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD816C6140');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD12469DE2');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD3569D950');
        $this->addSql('ALTER TABLE place_media DROP FOREIGN KEY FK_C35ABA2FDA6A219');
        $this->addSql('ALTER TABLE place_media DROP FOREIGN KEY FK_C35ABA2FABB37F3');
        $this->addSql('ALTER TABLE place_tag DROP FOREIGN KEY FK_C3BD172DA6A219');
        $this->addSql('ALTER TABLE place_tag DROP FOREIGN KEY FK_C3BD172BAD26311');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE article_destination');
        $this->addSql('DROP TABLE article_media');
        $this->addSql('DROP TABLE article_place');
        $this->addSql('DROP TABLE article_tag');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE destination');
        $this->addSql('DROP TABLE media_asset');
        $this->addSql('DROP TABLE place');
        $this->addSql('DROP TABLE place_media');
        $this->addSql('DROP TABLE place_tag');
        $this->addSql('DROP TABLE tag');
    }
}
