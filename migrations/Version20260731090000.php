<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend la vérification email à usage unique et neutralise les mots de passe des comptes non vérifiés existants.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Cette migration nécessite MySQL.',
        );

        $transientPasswordHash = password_hash(bin2hex(random_bytes(48)), PASSWORD_DEFAULT);
        if (!is_string($transientPasswordHash)) {
            throw new \RuntimeException('Impossible de générer le hash transitoire des comptes non vérifiés.');
        }

        $this->addSql('ALTER TABLE app_user ADD email_verification_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('UPDATE app_user SET password = ? WHERE is_verified = 0', [$transientPasswordHash]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les anciens mots de passe non vérifiés ont été neutralisés et ne peuvent pas être restaurés.',
        );
    }
}
