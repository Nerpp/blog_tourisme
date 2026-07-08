<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602221339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE city_visit_draft RENAME INDEX idx_9f775f8fd6fe9364 TO IDX_107D73FACCB03BD6');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX idx_7c8c1d64d6fe9364 TO IDX_F8F31F09CCB03BD6');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE city_visit_draft RENAME INDEX idx_107d73faccb03bd6 TO IDX_9F775F8FD6FE9364');
        $this->addSql('ALTER TABLE hike_draft RENAME INDEX idx_f8f31f09ccb03bd6 TO IDX_7C8C1D64D6FE9364');
    }
}
