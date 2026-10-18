<?php

namespace App\Entity;

use App\Repository\IncomeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Revenu mensuel : tous les mois, ou seulement sur une période (prime, mission ponctuelle…).
 * Son montant peut changer sur une période (IncomeChange), puis revient au montant habituel.
 */
#[ORM\Entity(repositoryClass: IncomeRepository::class)]
class Income
{
    use MonthPeriodTrait;

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

    /** Montant mensuel habituel en centimes. */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Indique un montant.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    private ?int $amount = null;

    /** @var Collection<int, IncomeChange> */
    #[ORM\OneToMany(targetEntity: IncomeChange::class, mappedBy: 'income', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startMonth' => 'ASC'])]
    private Collection $changes;

    public function __construct()
    {
        $this->changes = new ArrayCollection();
    }

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

    /** @return Collection<int, IncomeChange> */
    public function getChanges(): Collection
    {
        return $this->changes;
    }

    public function addChange(IncomeChange $change): static
    {
        if (!$this->changes->contains($change)) {
            $this->changes->add($change);
            $change->setIncome($this);
        }

        return $this;
    }

    public function removeChange(IncomeChange $change): static
    {
        $this->changes->removeElement($change);

        return $this;
    }

    /** Changement de montant qui s'applique au mois donné (le plus récent l'emporte s'ils se chevauchent). */
    public function getChangeIn(\DateTimeInterface $month): ?IncomeChange
    {
        $found = null;
        foreach ($this->changes as $change) {
            if ($change->isActiveIn($month) && (!$found || $change->getStartMonth() >= $found->getStartMonth())) {
                $found = $change;
            }
        }

        return $found;
    }

    /** Prochain changement de montant après le mois donné. */
    public function getNextChange(\DateTimeInterface $month): ?IncomeChange
    {
        foreach ($this->changes as $change) {
            if ('upcoming' === $change->getStatusIn($month)) {
                return $change;
            }
        }

        return null;
    }

    /** Montant perçu le mois donné : 0 hors période, sinon le montant changé ou habituel. */
    public function getAmountIn(\DateTimeInterface $month): int
    {
        if (!$this->isActiveIn($month)) {
            return 0;
        }

        return $this->getChangeIn($month)?->getAmount() ?? (int) $this->amount;
    }
}
