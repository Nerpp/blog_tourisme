<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518045320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(120) DEFAULT NULL, avatar_path VARCHAR(255) DEFAULT NULL, trusted_commenter TINYINT NOT NULL, approved_comments_count INT NOT NULL, rejected_comments_count INT NOT NULL, is_banned TINYINT NOT NULL, banned_at DATETIME DEFAULT NULL, ban_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, excerpt LONGTEXT DEFAULT NULL, content LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, seo_title VARCHAR(180) DEFAULT NULL, seo_description VARCHAR(255) DEFAULT NULL, canonical_url VARCHAR(500) DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, category_id INT DEFAULT NULL, featured_image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_23A0E66989D9B62 (slug), INDEX IDX_23A0E663569D950 (featured_image_id), INDEX idx_article_status (status), INDEX idx_article_published_at (published_at), INDEX idx_article_author (author_id), INDEX idx_article_category (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_destination (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, destination_id INT NOT NULL, INDEX idx_article_destination_article (article_id), INDEX idx_article_destination_destination (destination_id), INDEX idx_article_destination_position (position), UNIQUE INDEX uniq_article_destination (article_id, destination_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_article_media_article (article_id), INDEX idx_article_media_media_asset (media_asset_id), INDEX idx_article_media_role (role), INDEX idx_article_media_position (position), UNIQUE INDEX uniq_article_media_role (article_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_place (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, place_id INT NOT NULL, INDEX idx_article_place_article (article_id), INDEX idx_article_place_place (place_id), INDEX idx_article_place_position (position), UNIQUE INDEX uniq_article_place (article_id, place_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_tag (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, tag_id INT NOT NULL, INDEX idx_article_tag_article (article_id), INDEX idx_article_tag_tag (tag_id), UNIQUE INDEX uniq_article_tag (article_id, tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(180) NOT NULL, type VARCHAR(20) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_64C19C1989D9B62 (slug), INDEX idx_category_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city_visit_draft (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, google_maps_url LONGTEXT DEFAULT NULL, finished_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, destination_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_107D73FA989D9B62 (slug), INDEX IDX_107D73FA816C6140 (destination_id), INDEX IDX_107D73FAB03A8386 (created_by_id), INDEX idx_city_visit_draft_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city_visit_draft_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, city_visit_draft_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_city_visit_draft_media_city_visit_draft (city_visit_draft_id), INDEX idx_city_visit_draft_media_media_asset (media_asset_id), INDEX idx_city_visit_draft_media_role (role), INDEX idx_city_visit_draft_media_position (position), UNIQUE INDEX uniq_city_visit_draft_media_role (city_visit_draft_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city_visit_point (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(180) DEFAULT NULL, note LONGTEXT DEFAULT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, accuracy DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, created_at DATETIME NOT NULL, city_visit_draft_id INT NOT NULL, INDEX IDX_E1A4164AF4B41492 (city_visit_draft_id), INDEX idx_city_visit_point_type (type), INDEX idx_city_visit_point_position (position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city_visit_point_media (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, point_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_city_visit_point_media_point (point_id), INDEX idx_city_visit_point_media_media_asset (media_asset_id), UNIQUE INDEX uniq_city_visit_point_media (point_id, media_asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comment (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, moderation_reason VARCHAR(255) DEFAULT NULL, spam_score INT NOT NULL, reported_count INT NOT NULL, published_at DATETIME DEFAULT NULL, approved_at DATETIME DEFAULT NULL, moderated_at DATETIME DEFAULT NULL, edited_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT NOT NULL, article_id INT DEFAULT NULL, place_id INT DEFAULT NULL, parent_id INT DEFAULT NULL, moderated_by_id INT DEFAULT NULL, INDEX IDX_9474526C8EDA19B0 (moderated_by_id), INDEX idx_comment_status (status), INDEX idx_comment_created_at (created_at), INDEX idx_comment_published_at (published_at), INDEX idx_comment_approved_at (approved_at), INDEX idx_comment_author (author_id), INDEX idx_comment_article (article_id), INDEX idx_comment_place (place_id), INDEX idx_comment_parent (parent_id), INDEX idx_comment_reported_count (reported_count), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comment_report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(30) NOT NULL, message LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, comment_id INT NOT NULL, reporter_id INT DEFAULT NULL, reviewed_by_id INT DEFAULT NULL, INDEX IDX_E3C2F96FC6B21F1 (reviewed_by_id), INDEX idx_comment_report_status (status), INDEX idx_comment_report_comment (comment_id), INDEX idx_comment_report_reporter (reporter_id), UNIQUE INDEX uniq_comment_report_reporter (comment_id, reporter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE destination (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(180) NOT NULL, type VARCHAR(20) NOT NULL, code VARCHAR(40) DEFAULT NULL, description LONGTEXT DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, seo_title VARCHAR(180) DEFAULT NULL, seo_description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3EC63EAA989D9B62 (slug), INDEX idx_destination_type (type), INDEX idx_destination_parent (parent_id), INDEX idx_destination_coordinates (latitude, longitude), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_draft (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, finished_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, destination_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F8F31F09989D9B62 (slug), INDEX IDX_F8F31F09B03A8386 (created_by_id), INDEX IDX_F8F31F09816C6140 (destination_id), INDEX idx_hike_draft_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_draft_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, hike_draft_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_hike_draft_media_hike_draft (hike_draft_id), INDEX idx_hike_draft_media_media_asset (media_asset_id), INDEX idx_hike_draft_media_role (role), INDEX idx_hike_draft_media_position (position), UNIQUE INDEX uniq_hike_draft_media_role (hike_draft_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_point (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(180) DEFAULT NULL, note LONGTEXT DEFAULT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, accuracy DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, detected_commune_name VARCHAR(150) DEFAULT NULL, detected_commune_code VARCHAR(20) DEFAULT NULL, detected_department_name VARCHAR(150) DEFAULT NULL, detected_region_name VARCHAR(150) DEFAULT NULL, created_at DATETIME NOT NULL, hike_draft_id INT NOT NULL, INDEX IDX_92A7AB9807E537F (hike_draft_id), INDEX idx_hike_point_type (type), INDEX idx_hike_point_position (position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hike_point_media (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, point_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_hike_point_media_point (point_id), INDEX idx_hike_point_media_media_asset (media_asset_id), UNIQUE INDEX uniq_hike_point_media (point_id, media_asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_asset (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) DEFAULT NULL, alt_text VARCHAR(255) DEFAULT NULL, caption LONGTEXT DEFAULT NULL, media_type VARCHAR(20) NOT NULL, image_type VARCHAR(20) DEFAULT NULL, video_type VARCHAR(20) DEFAULT NULL, file_path VARCHAR(255) DEFAULT NULL, thumbnail_path VARCHAR(255) DEFAULT NULL, external_url VARCHAR(500) DEFAULT NULL, mime_type VARCHAR(120) DEFAULT NULL, file_size BIGINT DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, duration_seconds INT DEFAULT NULL, projection VARCHAR(80) DEFAULT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX idx_media_asset_media_type (media_type), INDEX idx_media_asset_image_type (image_type), INDEX idx_media_asset_video_type (video_type), INDEX idx_media_asset_uploaded_by (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE moderation_action_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(80) NOT NULL, target_type VARCHAR(80) NOT NULL, target_id INT DEFAULT NULL, summary LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, actor_id INT DEFAULT NULL, target_user_id INT DEFAULT NULL, INDEX IDX_835117CC10DAF24A (actor_id), INDEX IDX_835117CC6C066AFE (target_user_id), INDEX idx_moderation_action_log_action (action), INDEX idx_moderation_action_log_target (target_type, target_id), INDEX idx_moderation_action_log_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE moderation_keyword (id INT AUTO_INCREMENT NOT NULL, keyword VARCHAR(180) NOT NULL, type VARCHAR(20) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_moderation_keyword_type (type), INDEX idx_moderation_keyword_enabled (enabled), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, short_description LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, visit_duration_minutes INT DEFAULT NULL, difficulty VARCHAR(20) DEFAULT NULL, price_type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, seo_title VARCHAR(180) DEFAULT NULL, seo_description VARCHAR(255) DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, destination_id INT NOT NULL, category_id INT DEFAULT NULL, featured_image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_741D53CD989D9B62 (slug), INDEX IDX_741D53CD3569D950 (featured_image_id), INDEX idx_place_status (status), INDEX idx_place_published_at (published_at), INDEX idx_place_destination (destination_id), INDEX idx_place_category (category_id), INDEX idx_place_coordinates (latitude, longitude), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place_media (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, place_id INT NOT NULL, media_asset_id INT NOT NULL, INDEX idx_place_media_place (place_id), INDEX idx_place_media_media_asset (media_asset_id), INDEX idx_place_media_role (role), INDEX idx_place_media_position (position), UNIQUE INDEX uniq_place_media_role (place_id, media_asset_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE place_tag (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, place_id INT NOT NULL, tag_id INT NOT NULL, INDEX idx_place_tag_place (place_id), INDEX idx_place_tag_tag (tag_id), UNIQUE INDEX uniq_place_tag (place_id, tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_389B783989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_moderation_warning (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, comment_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_user_moderation_warning_user (user_id), INDEX idx_user_moderation_warning_comment (comment_id), INDEX idx_user_moderation_warning_created_by (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
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
        $this->addSql('ALTER TABLE city_visit_draft ADD CONSTRAINT FK_107D73FA816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE city_visit_draft ADD CONSTRAINT FK_107D73FAB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE city_visit_draft_media ADD CONSTRAINT FK_6F1C36C1F4B41492 FOREIGN KEY (city_visit_draft_id) REFERENCES city_visit_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE city_visit_draft_media ADD CONSTRAINT FK_6F1C36C1ABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE city_visit_point ADD CONSTRAINT FK_E1A4164AF4B41492 FOREIGN KEY (city_visit_draft_id) REFERENCES city_visit_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE city_visit_point_media ADD CONSTRAINT FK_CB5E327DC028CEA2 FOREIGN KEY (point_id) REFERENCES city_visit_point (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE city_visit_point_media ADD CONSTRAINT FK_CB5E327DABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C727ACA70 FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C8EDA19B0 FOREIGN KEY (moderated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96F8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE destination ADD CONSTRAINT FK_3EC63EAA727ACA70 FOREIGN KEY (parent_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE hike_draft ADD CONSTRAINT FK_F8F31F09B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE hike_draft ADD CONSTRAINT FK_F8F31F09816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE hike_draft_media ADD CONSTRAINT FK_AAF872A807E537F FOREIGN KEY (hike_draft_id) REFERENCES hike_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hike_draft_media ADD CONSTRAINT FK_AAF872AABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE hike_point ADD CONSTRAINT FK_92A7AB9807E537F FOREIGN KEY (hike_draft_id) REFERENCES hike_draft (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hike_point_media ADD CONSTRAINT FK_AEED8396C028CEA2 FOREIGN KEY (point_id) REFERENCES hike_point (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hike_point_media ADD CONSTRAINT FK_AEED8396ABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE media_asset ADD CONSTRAINT FK_1DB69EEDA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE moderation_action_log ADD CONSTRAINT FK_835117CC10DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE moderation_action_log ADD CONSTRAINT FK_835117CC6C066AFE FOREIGN KEY (target_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD3569D950 FOREIGN KEY (featured_image_id) REFERENCES media_asset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE place_media ADD CONSTRAINT FK_C35ABA2FDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE place_media ADD CONSTRAINT FK_C35ABA2FABB37F3 FOREIGN KEY (media_asset_id) REFERENCES media_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE place_tag ADD CONSTRAINT FK_C3BD172DA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE place_tag ADD CONSTRAINT FK_C3BD172BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE user_moderation_warning ADD CONSTRAINT FK_C749B1A2A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_moderation_warning ADD CONSTRAINT FK_C749B1A2F8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_moderation_warning ADD CONSTRAINT FK_C749B1A2B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
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
        $this->addSql('ALTER TABLE city_visit_draft DROP FOREIGN KEY FK_107D73FA816C6140');
        $this->addSql('ALTER TABLE city_visit_draft DROP FOREIGN KEY FK_107D73FAB03A8386');
        $this->addSql('ALTER TABLE city_visit_draft_media DROP FOREIGN KEY FK_6F1C36C1F4B41492');
        $this->addSql('ALTER TABLE city_visit_draft_media DROP FOREIGN KEY FK_6F1C36C1ABB37F3');
        $this->addSql('ALTER TABLE city_visit_point DROP FOREIGN KEY FK_E1A4164AF4B41492');
        $this->addSql('ALTER TABLE city_visit_point_media DROP FOREIGN KEY FK_CB5E327DC028CEA2');
        $this->addSql('ALTER TABLE city_visit_point_media DROP FOREIGN KEY FK_CB5E327DABB37F3');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C7294869C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CDA6A219');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C727ACA70');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C8EDA19B0');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_E3C2F96F8697D13');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_E3C2F96E1CFE6F5');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_E3C2F96FC6B21F1');
        $this->addSql('ALTER TABLE destination DROP FOREIGN KEY FK_3EC63EAA727ACA70');
        $this->addSql('ALTER TABLE hike_draft DROP FOREIGN KEY FK_F8F31F09B03A8386');
        $this->addSql('ALTER TABLE hike_draft DROP FOREIGN KEY FK_F8F31F09816C6140');
        $this->addSql('ALTER TABLE hike_draft_media DROP FOREIGN KEY FK_AAF872A807E537F');
        $this->addSql('ALTER TABLE hike_draft_media DROP FOREIGN KEY FK_AAF872AABB37F3');
        $this->addSql('ALTER TABLE hike_point DROP FOREIGN KEY FK_92A7AB9807E537F');
        $this->addSql('ALTER TABLE hike_point_media DROP FOREIGN KEY FK_AEED8396C028CEA2');
        $this->addSql('ALTER TABLE hike_point_media DROP FOREIGN KEY FK_AEED8396ABB37F3');
        $this->addSql('ALTER TABLE media_asset DROP FOREIGN KEY FK_1DB69EEDA2B28FE8');
        $this->addSql('ALTER TABLE moderation_action_log DROP FOREIGN KEY FK_835117CC10DAF24A');
        $this->addSql('ALTER TABLE moderation_action_log DROP FOREIGN KEY FK_835117CC6C066AFE');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD816C6140');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD12469DE2');
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD3569D950');
        $this->addSql('ALTER TABLE place_media DROP FOREIGN KEY FK_C35ABA2FDA6A219');
        $this->addSql('ALTER TABLE place_media DROP FOREIGN KEY FK_C35ABA2FABB37F3');
        $this->addSql('ALTER TABLE place_tag DROP FOREIGN KEY FK_C3BD172DA6A219');
        $this->addSql('ALTER TABLE place_tag DROP FOREIGN KEY FK_C3BD172BAD26311');
        $this->addSql('ALTER TABLE user_moderation_warning DROP FOREIGN KEY FK_C749B1A2A76ED395');
        $this->addSql('ALTER TABLE user_moderation_warning DROP FOREIGN KEY FK_C749B1A2F8697D13');
        $this->addSql('ALTER TABLE user_moderation_warning DROP FOREIGN KEY FK_C749B1A2B03A8386');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE article_destination');
        $this->addSql('DROP TABLE article_media');
        $this->addSql('DROP TABLE article_place');
        $this->addSql('DROP TABLE article_tag');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE city_visit_draft');
        $this->addSql('DROP TABLE city_visit_draft_media');
        $this->addSql('DROP TABLE city_visit_point');
        $this->addSql('DROP TABLE city_visit_point_media');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE comment_report');
        $this->addSql('DROP TABLE destination');
        $this->addSql('DROP TABLE hike_draft');
        $this->addSql('DROP TABLE hike_draft_media');
        $this->addSql('DROP TABLE hike_point');
        $this->addSql('DROP TABLE hike_point_media');
        $this->addSql('DROP TABLE media_asset');
        $this->addSql('DROP TABLE moderation_action_log');
        $this->addSql('DROP TABLE moderation_keyword');
        $this->addSql('DROP TABLE place');
        $this->addSql('DROP TABLE place_media');
        $this->addSql('DROP TABLE place_tag');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE user_moderation_warning');
    }
}
