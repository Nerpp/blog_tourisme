<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user moderation warnings and automatic ban fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_moderation_warning (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, comment_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_user_moderation_warning_user (user_id), INDEX idx_user_moderation_warning_comment (comment_id), INDEX idx_user_moderation_warning_created_by (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_moderation_warning ADD CONSTRAINT FK_4BB64D6EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_moderation_warning ADD CONSTRAINT FK_4BB64D6EF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_moderation_warning ADD CONSTRAINT FK_4BB64D6EB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE app_user ADD rejected_comments_count INT DEFAULT 0 NOT NULL, ADD is_banned TINYINT(1) DEFAULT 0 NOT NULL, ADD banned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD ban_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_moderation_warning DROP FOREIGN KEY FK_4BB64D6EA76ED395');
        $this->addSql('ALTER TABLE user_moderation_warning DROP FOREIGN KEY FK_4BB64D6EF8697D13');
        $this->addSql('ALTER TABLE user_moderation_warning DROP FOREIGN KEY FK_4BB64D6EB03A8386');
        $this->addSql('DROP TABLE user_moderation_warning');
        $this->addSql('ALTER TABLE app_user DROP rejected_comments_count, DROP is_banned, DROP banned_at, DROP ban_reason');
    }
}
