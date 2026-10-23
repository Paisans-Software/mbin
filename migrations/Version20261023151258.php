<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// FORK DIVERGENCE — do not offer this upstream: private magazines
final class Version20261023151258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add the magazine and requester columns used by access request notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD magazine_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD requester_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA3EB84A1D FOREIGN KEY (magazine_id) REFERENCES magazine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAED442CF4 FOREIGN KEY (requester_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA3EB84A1D ON notification (magazine_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAED442CF4 ON notification (requester_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA3EB84A1D');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAED442CF4');
        $this->addSql('DROP INDEX IDX_BF5476CA3EB84A1D');
        $this->addSql('DROP INDEX IDX_BF5476CAED442CF4');
        $this->addSql('ALTER TABLE notification DROP magazine_id');
        $this->addSql('ALTER TABLE notification DROP requester_id');
    }
}
