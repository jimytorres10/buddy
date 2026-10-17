<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017104857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE imported_transaction (id INT AUTO_INCREMENT NOT NULL, hash VARCHAR(64) NOT NULL, date DATE NOT NULL, raw_label VARCHAR(255) NOT NULL, label VARCHAR(120) NOT NULL, amount INT NOT NULL, status VARCHAR(16) NOT NULL, imported_at DATETIME NOT NULL, classified_at DATETIME DEFAULT NULL, user_id INT NOT NULL, category_id INT DEFAULT NULL, expense_id INT DEFAULT NULL, fixed_expense_id INT DEFAULT NULL, income_id INT DEFAULT NULL, INDEX idx_import_status (user_id, status), UNIQUE INDEX uniq_import_hash (user_id, hash), INDEX IDX_BD48758DA76ED395 (user_id), INDEX IDX_BD48758D12469DE2 (category_id), INDEX IDX_BD48758DF395DB7B (expense_id), INDEX IDX_BD48758DA8C0F41C (fixed_expense_id), INDEX IDX_BD48758D640ED2C0 (income_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE imported_transaction ADD CONSTRAINT FK_BD48758DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE imported_transaction ADD CONSTRAINT FK_BD48758D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE imported_transaction ADD CONSTRAINT FK_BD48758DF395DB7B FOREIGN KEY (expense_id) REFERENCES expense (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE imported_transaction ADD CONSTRAINT FK_BD48758DA8C0F41C FOREIGN KEY (fixed_expense_id) REFERENCES fixed_expense (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE imported_transaction ADD CONSTRAINT FK_BD48758D640ED2C0 FOREIGN KEY (income_id) REFERENCES income (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE imported_transaction DROP FOREIGN KEY FK_BD48758DA76ED395');
        $this->addSql('ALTER TABLE imported_transaction DROP FOREIGN KEY FK_BD48758D12469DE2');
        $this->addSql('ALTER TABLE imported_transaction DROP FOREIGN KEY FK_BD48758DF395DB7B');
        $this->addSql('ALTER TABLE imported_transaction DROP FOREIGN KEY FK_BD48758DA8C0F41C');
        $this->addSql('ALTER TABLE imported_transaction DROP FOREIGN KEY FK_BD48758D640ED2C0');
        $this->addSql('DROP TABLE imported_transaction');
    }
}
