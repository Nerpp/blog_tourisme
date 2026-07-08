<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529185911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comment reply and mention notifications.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comment_reply_notification (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, read_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, recipient_id INT NOT NULL, comment_id INT NOT NULL, triggered_by_id INT DEFAULT NULL, INDEX IDX_992D36C1E92F8F78 (recipient_id), INDEX IDX_992D36C163C5923F (triggered_by_id), INDEX idx_comment_reply_notification_recipient_read (recipient_id, read_at), INDEX idx_comment_reply_notification_comment (comment_id), UNIQUE INDEX uniq_comment_reply_notification_recipient_comment (recipient_id, comment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE comment_reply_notification ADD CONSTRAINT FK_992D36C1E92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_reply_notification ADD CONSTRAINT FK_992D36C1F8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_reply_notification ADD CONSTRAINT FK_992D36C163C5923F FOREIGN KEY (triggered_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment_reply_notification DROP FOREIGN KEY FK_992D36C1E92F8F78');
        $this->addSql('ALTER TABLE comment_reply_notification DROP FOREIGN KEY FK_992D36C1F8697D13');
        $this->addSql('ALTER TABLE comment_reply_notification DROP FOREIGN KEY FK_992D36C163C5923F');
        $this->addSql('DROP TABLE comment_reply_notification');
    }
}
