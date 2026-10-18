<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Montant différent d'un revenu sur une période (ex. salaire réduit pendant 2027).
 * Hors de cette période, le revenu garde son montant habituel.
 */
#[ORM\Entity]
class IncomeChange
{
    use MonthPeriodTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'changes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Income $income = null;

    /** Montant mensuel en centimes pendant la période (0 = plus rien ces mois-là). */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Indique un montant.')]
    #[Assert\PositiveOrZero(message: 'Le montant doit être positif.')]
    private ?int $amount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncome(): ?Income
    {
        return $this->income;
    }

    public function setIncome(?Income $income): static
    {
        $this->income = $income;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->income?->getUser();
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    #[Assert\Callback]
    public function validateStart(ExecutionContextInterface $context): void
    {
        if (null === $this->startMonth) {
            $context->buildViolation('Indique à partir de quel mois.')->atPath('startMonth')->addViolation();
        }
    }
}
