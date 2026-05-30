<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530102716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin pin marker to comments.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment ADD pinned_at DATETIME DEFAULT NULL, ADD pinned_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C59662AC1 FOREIGN KEY (pinned_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_comment_pinned_at ON comment (pinned_at)');
        $this->addSql('CREATE INDEX idx_comment_pinned_by ON comment (pinned_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C59662AC1');
        $this->addSql('DROP INDEX idx_comment_pinned_at ON comment');
        $this->addSql('DROP INDEX idx_comment_pinned_by ON comment');
        $this->addSql('ALTER TABLE comment DROP pinned_at, DROP pinned_by_id');
    }
}
