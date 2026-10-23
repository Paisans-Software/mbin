<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// FORK DIVERGENCE — do not offer this upstream: private magazines
final class Version20261023010319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add magazine_member, the users admitted to private and hidden magazines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE magazine_member_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE magazine_member (id INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, magazine_id INT NOT NULL, user_id INT NOT NULL, added_by_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9128B5973EB84A1D ON magazine_member (magazine_id)');
        $this->addSql('CREATE INDEX IDX_9128B597A76ED395 ON magazine_member (user_id)');
        $this->addSql('CREATE INDEX IDX_9128B59755B127A4 ON magazine_member (added_by_id)');
        $this->addSql('CREATE UNIQUE INDEX magazine_member_idx ON magazine_member (magazine_id, user_id)');
        $this->addSql('ALTER TABLE magazine_member ADD CONSTRAINT FK_9128B5973EB84A1D FOREIGN KEY (magazine_id) REFERENCES magazine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE magazine_member ADD CONSTRAINT FK_9128B597A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE magazine_member ADD CONSTRAINT FK_9128B59755B127A4 FOREIGN KEY (added_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine_member DROP CONSTRAINT FK_9128B5973EB84A1D');
        $this->addSql('ALTER TABLE magazine_member DROP CONSTRAINT FK_9128B597A76ED395');
        $this->addSql('ALTER TABLE magazine_member DROP CONSTRAINT FK_9128B59755B127A4');
        $this->addSql('DROP TABLE magazine_member');
        $this->addSql('DROP SEQUENCE magazine_member_id_seq CASCADE');
    }
}
