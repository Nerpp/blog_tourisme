<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add separate geographic destinations for hikes and city visits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hike_draft ADD geographic_destination_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE hike_draft ADD CONSTRAINT FK_7C8C1D64D6FE9364 FOREIGN KEY (geographic_destination_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7C8C1D64D6FE9364 ON hike_draft (geographic_destination_id)');
        $this->addSql('ALTER TABLE city_visit_draft ADD geographic_destination_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE city_visit_draft ADD CONSTRAINT FK_9F775F8FD6FE9364 FOREIGN KEY (geographic_destination_id) REFERENCES destination (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9F775F8FD6FE9364 ON city_visit_draft (geographic_destination_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE city_visit_draft DROP FOREIGN KEY FK_9F775F8FD6FE9364');
        $this->addSql('DROP INDEX IDX_9F775F8FD6FE9364 ON city_visit_draft');
        $this->addSql('ALTER TABLE city_visit_draft DROP geographic_destination_id');
        $this->addSql('ALTER TABLE hike_draft DROP FOREIGN KEY FK_7C8C1D64D6FE9364');
        $this->addSql('DROP INDEX IDX_7C8C1D64D6FE9364 ON hike_draft');
        $this->addSql('ALTER TABLE hike_draft DROP geographic_destination_id');
    }
}
