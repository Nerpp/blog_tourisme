<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Centralise les commentaires dans des fils partagés par les articles, lieux, randonnées et visites.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Cette migration nécessite MySQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE comment_thread (
                id INT AUTO_INCREMENT NOT NULL,
                content_type VARCHAR(20) NOT NULL,
                source_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_comment_thread_content_type (content_type),
                UNIQUE INDEX uniq_comment_thread_source (content_type, source_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql("INSERT INTO comment_thread (content_type, source_id, created_at) SELECT 'article', id, created_at FROM article");
        $this->addSql("INSERT INTO comment_thread (content_type, source_id, created_at) SELECT 'place', id, created_at FROM place");
        $this->addSql("INSERT INTO comment_thread (content_type, source_id, created_at) SELECT 'hike', id, created_at FROM hike_draft");
        $this->addSql("INSERT INTO comment_thread (content_type, source_id, created_at) SELECT 'city-visit', id, created_at FROM city_visit_draft");

        $this->addSql('ALTER TABLE article ADD comment_thread_id INT DEFAULT NULL');
        $this->addSql("UPDATE article a INNER JOIN comment_thread t ON t.content_type = 'article' AND t.source_id = a.id SET a.comment_thread_id = t.id");
        $this->addSql('ALTER TABLE article MODIFY comment_thread_id INT NOT NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66BEEA14F FOREIGN KEY (comment_thread_id) REFERENCES comment_thread (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_23A0E66BEEA14F ON article (comment_thread_id)');

        $this->addSql('ALTER TABLE place ADD comment_thread_id INT DEFAULT NULL');
        $this->addSql("UPDATE place p INNER JOIN comment_thread t ON t.content_type = 'place' AND t.source_id = p.id SET p.comment_thread_id = t.id");
        $this->addSql('ALTER TABLE place MODIFY comment_thread_id INT NOT NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CDBEEA14F FOREIGN KEY (comment_thread_id) REFERENCES comment_thread (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_741D53CDBEEA14F ON place (comment_thread_id)');

        $this->addSql('ALTER TABLE hike_draft ADD comment_thread_id INT DEFAULT NULL');
        $this->addSql("UPDATE hike_draft h INNER JOIN comment_thread t ON t.content_type = 'hike' AND t.source_id = h.id SET h.comment_thread_id = t.id");
        $this->addSql('ALTER TABLE hike_draft MODIFY comment_thread_id INT NOT NULL');
        $this->addSql('ALTER TABLE hike_draft ADD CONSTRAINT FK_F8F31F09BEEA14F FOREIGN KEY (comment_thread_id) REFERENCES comment_thread (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F8F31F09BEEA14F ON hike_draft (comment_thread_id)');

        $this->addSql('ALTER TABLE city_visit_draft ADD comment_thread_id INT DEFAULT NULL');
        $this->addSql("UPDATE city_visit_draft c INNER JOIN comment_thread t ON t.content_type = 'city-visit' AND t.source_id = c.id SET c.comment_thread_id = t.id");
        $this->addSql('ALTER TABLE city_visit_draft MODIFY comment_thread_id INT NOT NULL');
        $this->addSql('ALTER TABLE city_visit_draft ADD CONSTRAINT FK_107D73FABEEA14F FOREIGN KEY (comment_thread_id) REFERENCES comment_thread (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_107D73FABEEA14F ON city_visit_draft (comment_thread_id)');

        $this->addSql('ALTER TABLE comment ADD thread_id INT DEFAULT NULL');
        $this->addSql('UPDATE comment c INNER JOIN article a ON a.id = c.article_id SET c.thread_id = a.comment_thread_id WHERE c.article_id IS NOT NULL');
        $this->addSql('UPDATE comment c INNER JOIN place p ON p.id = c.place_id SET c.thread_id = p.comment_thread_id WHERE c.place_id IS NOT NULL');

        // Les anciennes suppressions de lieux pouvaient laisser des commentaires sans cible.
        // Ils restent conservés dans un fil inaccessible, regroupés avec leurs réponses.
        $this->addSql(<<<'SQL'
            INSERT INTO comment_thread (content_type, source_id, created_at)
            SELECT 'article', -COALESCE(parent_id, id), MIN(created_at)
            FROM comment
            WHERE thread_id IS NULL
            GROUP BY -COALESCE(parent_id, id)
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE comment c
            INNER JOIN comment_thread t
                ON t.content_type = 'article'
                AND t.source_id = -COALESCE(c.parent_id, c.id)
            SET c.thread_id = t.id
            WHERE c.thread_id IS NULL
        SQL);

        $this->addSql('ALTER TABLE comment MODIFY thread_id INT NOT NULL');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C7294869C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CDA6A219');
        $this->addSql('DROP INDEX idx_comment_article ON comment');
        $this->addSql('DROP INDEX idx_comment_place ON comment');
        $this->addSql('ALTER TABLE comment DROP article_id, DROP place_id');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CE2904019 FOREIGN KEY (thread_id) REFERENCES comment_thread (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_comment_thread ON comment (thread_id)');

        $this->addSql('DROP INDEX uniq_comment_thread_source ON comment_thread');
        $this->addSql('ALTER TABLE comment_thread DROP source_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Le retour arrière supprimerait les cibles randonnée et visite des commentaires migrés.',
        );
    }
}
