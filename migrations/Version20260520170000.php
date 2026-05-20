<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add privacy-friendly traffic events storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE traffic_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, method VARCHAR(10) NOT NULL, path VARCHAR(500) NOT NULL, route_name VARCHAR(120) DEFAULT NULL, route_params JSON DEFAULT NULL, content_type VARCHAR(40) DEFAULT NULL, content_id INT DEFAULT NULL, content_title VARCHAR(255) DEFAULT NULL, status_code INT NOT NULL, duration_ms INT DEFAULT NULL, referrer_host VARCHAR(255) DEFAULT NULL, utm_source VARCHAR(120) DEFAULT NULL, utm_medium VARCHAR(120) DEFAULT NULL, utm_campaign VARCHAR(180) DEFAULT NULL, device_type VARCHAR(20) DEFAULT NULL, browser_family VARCHAR(80) DEFAULT NULL, os_family VARCHAR(80) DEFAULT NULL, visitor_hash VARCHAR(64) DEFAULT NULL, is_bot TINYINT(1) NOT NULL, user_agent_hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_traffic_event_occurred_at (occurred_at), INDEX idx_traffic_event_route (route_name), INDEX idx_traffic_event_content (content_type, content_id), INDEX idx_traffic_event_path (path), INDEX idx_traffic_event_status (status_code), INDEX idx_traffic_event_referrer_host (referrer_host), INDEX idx_traffic_event_visitor_hash (visitor_hash), INDEX idx_traffic_event_is_bot (is_bot), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_event');
    }
}
