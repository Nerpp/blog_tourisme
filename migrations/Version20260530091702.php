<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530091702 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comment likes and admin heart marker.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comment_like (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, comment_id INT NOT NULL, user_id INT NOT NULL, INDEX idx_comment_like_comment (comment_id), INDEX idx_comment_like_user (user_id), UNIQUE INDEX uniq_comment_like_comment_user (comment_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE comment_like ADD CONSTRAINT FK_8A55E25FF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_like ADD CONSTRAINT FK_8A55E25FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD admin_hearted_at DATETIME DEFAULT NULL, ADD admin_hearted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C8DF1AE58 FOREIGN KEY (admin_hearted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_comment_admin_hearted_by ON comment (admin_hearted_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment_like DROP FOREIGN KEY FK_8A55E25FF8697D13');
        $this->addSql('ALTER TABLE comment_like DROP FOREIGN KEY FK_8A55E25FA76ED395');
        $this->addSql('DROP TABLE comment_like');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C8DF1AE58');
        $this->addSql('DROP INDEX idx_comment_admin_hearted_by ON comment');
        $this->addSql('ALTER TABLE comment DROP admin_hearted_at, DROP admin_hearted_by_id');
    }
}
