<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704124522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the production access bootstrap marker and administrator role audit trail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_role_audit (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(16) NOT NULL, role VARCHAR(32) NOT NULL, source VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, actor_id INT DEFAULT NULL, target_user_id INT NOT NULL, INDEX IDX_ADFAB1D310DAF24A (actor_id), INDEX IDX_ADFAB1D36C066AFE (target_user_id), INDEX idx_admin_role_audit_target_created (target_user_id, created_at), INDEX idx_admin_role_audit_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE production_access_bootstrap (id INT AUTO_INCREMENT NOT NULL, completed_at DATETIME NOT NULL, configuration_fingerprint VARCHAR(64) DEFAULT NULL, executed_from_file VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE admin_role_audit ADD CONSTRAINT FK_ADFAB1D310DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE admin_role_audit ADD CONSTRAINT FK_ADFAB1D36C066AFE FOREIGN KEY (target_user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_role_audit DROP FOREIGN KEY FK_ADFAB1D310DAF24A');
        $this->addSql('ALTER TABLE admin_role_audit DROP FOREIGN KEY FK_ADFAB1D36C066AFE');
        $this->addSql('DROP TABLE admin_role_audit');
        $this->addSql('DROP TABLE production_access_bootstrap');
    }
}
