<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add JSON media variants storage to media assets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_asset ADD variants JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_asset DROP variants');
    }
}
