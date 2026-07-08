<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add article links to hike and city visit public contents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_city_visit (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, city_visit_draft_id INT NOT NULL, INDEX idx_article_city_visit_article (article_id), INDEX idx_article_city_visit_city_visit (city_visit_draft_id), INDEX idx_article_city_visit_position (position), INDEX idx_article_city_visit_role (role), UNIQUE INDEX uniq_article_city_visit (article_id, city_visit_draft_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article_hike (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, role VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, hike_draft_id INT NOT NULL, INDEX idx_article_hike_article (article_id), INDEX idx_article_hike_hike_draft (hike_draft_id), INDEX idx_article_hike_position (position), INDEX idx_article_hike_role (role), UNIQUE INDEX uniq_article_hike (article_id, hike_draft_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article_city_visit ADD CONSTRAINT FK_86F0499D7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_city_visit ADD CONSTRAINT FK_86F0499DF4B41492 FOREIGN KEY (city_visit_draft_id) REFERENCES city_visit_draft (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE article_hike ADD CONSTRAINT FK_8497C5B17294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_hike ADD CONSTRAINT FK_8497C5B107E537F FOREIGN KEY (hike_draft_id) REFERENCES hike_draft (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_city_visit DROP FOREIGN KEY FK_86F0499D7294869C');
        $this->addSql('ALTER TABLE article_city_visit DROP FOREIGN KEY FK_86F0499DF4B41492');
        $this->addSql('ALTER TABLE article_hike DROP FOREIGN KEY FK_8497C5B17294869C');
        $this->addSql('ALTER TABLE article_hike DROP FOREIGN KEY FK_8497C5B107E537F');
        $this->addSql('DROP TABLE article_city_visit');
        $this->addSql('DROP TABLE article_hike');
    }
}
