<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow scouting places to be created without a destination sector.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD816C6140');
        $this->addSql('ALTER TABLE place CHANGE destination_id destination_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place DROP FOREIGN KEY FK_741D53CD816C6140');
        $this->addSql('ALTER TABLE place CHANGE destination_id destination_id INT NOT NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD816C6140 FOREIGN KEY (destination_id) REFERENCES destination (id) ON DELETE RESTRICT');
    }
}
