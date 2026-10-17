<?php

namespace App\Entity;

use App\Repository\MonthlyBudgetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Budget d'une catégorie ajusté pour un mois précis ; remplace le budget habituel ce mois-là.
 */
#[ORM\Entity(repositoryClass: MonthlyBudgetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_monthly_budget', columns: ['category_id', 'month'])]
#[ORM\Index(name: 'idx_monthly_budget_user_month', columns: ['user_id', 'month'])]
class MonthlyBudget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Category $category = null;

    /** 1er jour du mois concerné. */
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $month = null;

    /** Budget du mois en centimes. */
    #[ORM\Column]
    private int $amount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getMonth(): ?\DateTimeImmutable
    {
        return $this->month;
    }

    public function setMonth(\DateTimeImmutable $month): static
    {
        $this->month = $month->modify('first day of this month midnight');

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }
}
