<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512130654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user CHANGE rejected_comments_count rejected_comments_count INT NOT NULL, CHANGE is_banned is_banned TINYINT NOT NULL, CHANGE banned_at banned_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX uniq_hike_draft_slug TO UNIQ_F8F31F09989D9B62');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX idx_hike_draft_created_by TO IDX_F8F31F09B03A8386');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX idx_hike_draft_destination TO IDX_F8F31F09816C6140');
        $this->addSql('ALTER TABLE hike_point RENAME INDEX idx_hike_point_draft TO IDX_92A7AB9807E537F');
        $this->addSql('ALTER TABLE user_moderation_warning CHANGE created_at created_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user CHANGE rejected_comments_count rejected_comments_count INT DEFAULT 0 NOT NULL, CHANGE is_banned is_banned TINYINT DEFAULT 0 NOT NULL, CHANGE banned_at banned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX uniq_f8f31f09989d9b62 TO UNIQ_HIKE_DRAFT_SLUG');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX idx_f8f31f09b03a8386 TO IDX_HIKE_DRAFT_CREATED_BY');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX idx_f8f31f09816c6140 TO IDX_HIKE_DRAFT_DESTINATION');
        $this->addSql('ALTER TABLE hike_point RENAME INDEX idx_92a7ab9807e537f TO IDX_HIKE_POINT_DRAFT');
        $this->addSql('ALTER TABLE user_moderation_warning CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
