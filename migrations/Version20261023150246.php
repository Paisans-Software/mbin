<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// FORK DIVERGENCE — do not offer this upstream: private magazines
final class Version20261023150246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add requests for access to private magazines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE magazine_access_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE magazine_access_request (id INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, magazine_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_689F57773EB84A1D ON magazine_access_request (magazine_id)');
        $this->addSql('CREATE INDEX IDX_689F5777A76ED395 ON magazine_access_request (user_id)');
        $this->addSql('CREATE UNIQUE INDEX magazine_access_request_idx ON magazine_access_request (magazine_id, user_id)');
        $this->addSql('ALTER TABLE magazine_access_request ADD CONSTRAINT FK_689F57773EB84A1D FOREIGN KEY (magazine_id) REFERENCES magazine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE magazine_access_request ADD CONSTRAINT FK_689F5777A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine_access_request DROP CONSTRAINT FK_689F57773EB84A1D');
        $this->addSql('ALTER TABLE magazine_access_request DROP CONSTRAINT FK_689F5777A76ED395');
        $this->addSql('DROP TABLE magazine_access_request');
        $this->addSql('DROP SEQUENCE magazine_access_request_id_seq CASCADE');
    }
}
