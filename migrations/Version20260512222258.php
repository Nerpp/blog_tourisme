<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512222258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add moderation action log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moderation_action_log (id INT AUTO_INCREMENT NOT NULL, actor_id INT DEFAULT NULL, target_user_id INT DEFAULT NULL, action VARCHAR(80) NOT NULL, target_type VARCHAR(80) NOT NULL, target_id INT DEFAULT NULL, summary LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_moderation_action_log_action (action), INDEX idx_moderation_action_log_target (target_type, target_id), INDEX idx_moderation_action_log_created_at (created_at), INDEX IDX_MODERATION_ACTION_LOG_ACTOR (actor_id), INDEX IDX_MODERATION_ACTION_LOG_TARGET_USER (target_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE moderation_action_log ADD CONSTRAINT FK_MODERATION_ACTION_LOG_ACTOR FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE moderation_action_log ADD CONSTRAINT FK_MODERATION_ACTION_LOG_TARGET_USER FOREIGN KEY (target_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_action_log DROP FOREIGN KEY FK_MODERATION_ACTION_LOG_ACTOR');
        $this->addSql('ALTER TABLE moderation_action_log DROP FOREIGN KEY FK_MODERATION_ACTION_LOG_TARGET_USER');
        $this->addSql('DROP TABLE moderation_action_log');
    }
}
