<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017102004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE monthly_budget (id INT AUTO_INCREMENT NOT NULL, month DATE NOT NULL, amount INT NOT NULL, user_id INT NOT NULL, category_id INT NOT NULL, INDEX idx_monthly_budget_user_month (user_id, month), UNIQUE INDEX uniq_monthly_budget (category_id, month), INDEX IDX_905264B0A76ED395 (user_id), INDEX IDX_905264B012469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE monthly_budget ADD CONSTRAINT FK_905264B0A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE monthly_budget ADD CONSTRAINT FK_905264B012469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE monthly_budget DROP FOREIGN KEY FK_905264B0A76ED395');
        $this->addSql('ALTER TABLE monthly_budget DROP FOREIGN KEY FK_905264B012469DE2');
        $this->addSql('DROP TABLE monthly_budget');
    }
}
