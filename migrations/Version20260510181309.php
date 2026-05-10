<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510181309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment RENAME INDEX idx_9474526c75f05040 TO IDX_9474526C8EDA19B0');
        $this->addSql('ALTER TABLE comment_report RENAME INDEX idx_7a5f409075f05040 TO IDX_E3C2F96FC6B21F1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment RENAME INDEX idx_9474526c8eda19b0 TO IDX_9474526C75F05040');
        $this->addSql('ALTER TABLE comment_report RENAME INDEX idx_e3c2f96fc6b21f1 TO IDX_7A5F409075F05040');
    }
}
