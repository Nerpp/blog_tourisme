<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512203813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action_log CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE moderation_action_log RENAME INDEX idx_moderation_action_log_actor TO IDX_835117CC10DAF24A');
        $this->addSql('ALTER TABLE moderation_action_log RENAME INDEX idx_moderation_action_log_target_user TO IDX_835117CC6C066AFE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action_log CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE moderation_action_log RENAME INDEX idx_835117cc10daf24a TO IDX_MODERATION_ACTION_LOG_ACTOR');
        $this->addSql('ALTER TABLE moderation_action_log RENAME INDEX idx_835117cc6c066afe TO IDX_MODERATION_ACTION_LOG_TARGET_USER');
    }
}
