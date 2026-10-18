<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018065813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revenus : période facultative et changements de montant sur une période';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE income_change (id INT AUTO_INCREMENT NOT NULL, amount INT NOT NULL, start_month DATE DEFAULT NULL, end_month DATE DEFAULT NULL, income_id INT NOT NULL, INDEX IDX_BF848817640ED2C0 (income_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE income_change ADD CONSTRAINT FK_BF848817640ED2C0 FOREIGN KEY (income_id) REFERENCES income (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE income ADD start_month DATE DEFAULT NULL, ADD end_month DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE income_change DROP FOREIGN KEY FK_BF848817640ED2C0');
        $this->addSql('DROP TABLE income_change');
        $this->addSql('ALTER TABLE income DROP start_month, DROP end_month');
    }
}
