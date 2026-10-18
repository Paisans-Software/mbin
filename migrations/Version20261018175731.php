<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018175731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'record whether an OIDC-linked user uploaded their own avatar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD oidc_avatar_overridden BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP oidc_avatar_overridden');
    }
}
