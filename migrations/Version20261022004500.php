<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// FORK DIVERGENCE — do not offer this upstream: private magazines
final class Version20261022004500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add the public, private or hidden access level to magazines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine ADD access VARCHAR(255) DEFAULT \'public\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine DROP access');
    }
}
