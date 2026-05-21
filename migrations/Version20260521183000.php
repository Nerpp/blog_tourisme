<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add publication notification log to prevent duplicate emails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE publication_notification_log (id INT AUTO_INCREMENT NOT NULL, content_type VARCHAR(40) NOT NULL, content_id INT NOT NULL, sent_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', recipient_count INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_publication_notification_sent_at (sent_at), UNIQUE INDEX uniq_publication_notification_content (content_type, content_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE publication_notification_log');
    }
}
