<?php

namespace App\Entity;

use App\Repository\FixedExpenseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: FixedExpenseRepository::class)]
class FixedExpense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Indique un libellé.')]
    #[Assert\Length(max: 80)]
    private ?string $label = null;

    /** Montant mensuel en centimes. */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Indique un montant.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    private ?int $amount = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /** Premier mois concerné (1er du mois), null = depuis toujours. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startMonth = null;

    /** Dernier mois concerné (1er du mois), null = sans fin. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endMonth = null;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

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

    /** La dépense s'applique-t-elle au mois donné ? */
    public function isActiveIn(\DateTimeInterface $month): bool
    {
        return 'active' === $this->getStatusIn($month);
    }

    /** 'past' (terminée), 'upcoming' (à venir) ou 'active' pour le mois donné. */
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
