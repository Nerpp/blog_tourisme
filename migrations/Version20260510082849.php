<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510082849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user authentication fields, comments, moderation keywords, and comment reports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD trusted_commenter TINYINT(1) NOT NULL, ADD approved_comments_count INT NOT NULL');

        $this->addSql('CREATE TABLE comment (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, moderation_reason VARCHAR(255) DEFAULT NULL, spam_score INT NOT NULL, reported_count INT NOT NULL, published_at DATETIME DEFAULT NULL, approved_at DATETIME DEFAULT NULL, moderated_at DATETIME DEFAULT NULL, edited_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT NOT NULL, article_id INT DEFAULT NULL, place_id INT DEFAULT NULL, parent_id INT DEFAULT NULL, moderated_by_id INT DEFAULT NULL, INDEX idx_comment_status (status), INDEX idx_comment_created_at (created_at), INDEX idx_comment_published_at (published_at), INDEX idx_comment_approved_at (approved_at), INDEX idx_comment_author (author_id), INDEX idx_comment_article (article_id), INDEX idx_comment_place (place_id), INDEX idx_comment_parent (parent_id), INDEX idx_comment_reported_count (reported_count), INDEX IDX_9474526C75F05040 (moderated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comment_report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(30) NOT NULL, message LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, comment_id INT NOT NULL, reporter_id INT DEFAULT NULL, reviewed_by_id INT DEFAULT NULL, INDEX idx_comment_report_status (status), INDEX idx_comment_report_comment (comment_id), INDEX idx_comment_report_reporter (reporter_id), INDEX IDX_7A5F409075F05040 (reviewed_by_id), UNIQUE INDEX uniq_comment_report_reporter (comment_id, reporter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE moderation_keyword (id INT AUTO_INCREMENT NOT NULL, keyword VARCHAR(180) NOT NULL, type VARCHAR(20) NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_moderation_keyword_type (type), INDEX idx_moderation_keyword_enabled (enabled), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_AUTHOR FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_ARTICLE FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_PLACE FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_PARENT FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_MODERATED_BY FOREIGN KEY (moderated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT chk_comment_one_target CHECK ((article_id IS NOT NULL AND place_id IS NULL) OR (article_id IS NULL AND place_id IS NOT NULL))');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_COMMENT_REPORT_COMMENT FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_COMMENT_REPORT_REPORTER FOREIGN KEY (reporter_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_COMMENT_REPORT_REVIEWED_BY FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_COMMENT_REPORT_COMMENT');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_COMMENT_REPORT_REPORTER');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_COMMENT_REPORT_REVIEWED_BY');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_AUTHOR');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_ARTICLE');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_PLACE');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_PARENT');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_MODERATED_BY');
        $this->addSql('DROP TABLE comment_report');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE moderation_keyword');
        $this->addSql('ALTER TABLE app_user DROP trusted_commenter, DROP approved_comments_count');
    }
}
