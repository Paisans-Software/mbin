<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023150352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.shadow_of_entry_id and entry.shadow_of_post_id for shadow threads in private magazines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry ADD shadow_of_entry_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE entry ADD shadow_of_post_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D70B060285A FOREIGN KEY (shadow_of_entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D701D463CA2 FOREIGN KEY (shadow_of_post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2B219D70B060285A ON entry (shadow_of_entry_id)');
        $this->addSql('CREATE INDEX IDX_2B219D701D463CA2 ON entry (shadow_of_post_id)');
        $this->addSql('CREATE UNIQUE INDEX entry_shadow_entry_idx ON entry (magazine_id, shadow_of_entry_id)');
        $this->addSql('CREATE UNIQUE INDEX entry_shadow_post_idx ON entry (magazine_id, shadow_of_post_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP CONSTRAINT FK_2B219D70B060285A');
        $this->addSql('ALTER TABLE entry DROP CONSTRAINT FK_2B219D701D463CA2');
        $this->addSql('DROP INDEX IDX_2B219D70B060285A');
        $this->addSql('DROP INDEX IDX_2B219D701D463CA2');
        $this->addSql('DROP INDEX entry_shadow_entry_idx');
        $this->addSql('DROP INDEX entry_shadow_post_idx');
        $this->addSql('ALTER TABLE entry DROP shadow_of_entry_id');
        $this->addSql('ALTER TABLE entry DROP shadow_of_post_id');
    }
}
