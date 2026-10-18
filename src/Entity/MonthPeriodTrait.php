<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Période en mois (bornes incluses, facultatives) : dépenses fixes, revenus, changements de revenu.
 */
trait MonthPeriodTrait
{
    /** Premier mois concerné (1er du mois), null = depuis toujours. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startMonth = null;

    /** Dernier mois concerné (1er du mois), null = sans fin. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endMonth = null;

    public function getStartMonth(): ?\DateTimeImmutable
    {
        return $this->startMonth;
    }

    public function setStartMonth(?\DateTimeImmutable $startMonth): static
    {
        $this->startMonth = $startMonth;

        return $this;
    }

    public function getEndMonth(): ?\DateTimeImmutable
    {
        return $this->endMonth;
    }

    public function setEndMonth(?\DateTimeImmutable $endMonth): static
    {
        $this->endMonth = $endMonth;

        return $this;
    }

    public function hasPeriod(): bool
    {
        return null !== $this->startMonth || null !== $this->endMonth;
    }

    /** S'applique-t-il au mois donné ? */
    public function isActiveIn(\DateTimeInterface $month): bool
    {
        return 'active' === $this->getStatusIn($month);
    }

    /** 'past' (terminé), 'upcoming' (à venir) ou 'active' pour le mois donné. */
    public function getStatusIn(\DateTimeInterface $month): string
    {
        $key = $month->format('Y-m');

        return match (true) {
            null !== $this->endMonth && $this->endMonth->format('Y-m') < $key => 'past',
            null !== $this->startMonth && $this->startMonth->format('Y-m') > $key => 'upcoming',
            default => 'active',
        };
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->startMonth && $this->endMonth && $this->endMonth < $this->startMonth) {
            $context->buildViolation('Le mois de fin doit être après le mois de début.')
                ->atPath('endMonth')
                ->addViolation();
        }
    }
}
