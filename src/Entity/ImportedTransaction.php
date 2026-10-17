<?php

namespace App\Entity;

use App\Repository\ImportedTransactionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opération bancaire importée (fichier OFX/CSV), en attente de tri ou déjà classée.
 */
#[ORM\Entity(repositoryClass: ImportedTransactionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_import_hash', columns: ['user_id', 'hash'])]
#[ORM\Index(name: 'idx_import_status', columns: ['user_id', 'status'])]
class ImportedTransaction
{
    public const PENDING = 'pending';
    public const EXPENSE = 'expense';
    public const FIXED = 'fixed';
    public const INCOME = 'income';
    public const IGNORED = 'ignored';
    /** Pointée sur une dépense fixe existante (rien n'est créé, déjà comptée dans les fixes). */
    public const MATCHED = 'matched';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Empreinte anti-doublon (identifiant bancaire ou date + montant + libellé). */
    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /** Libellé tel qu'exporté par la banque. */
    #[ORM\Column(length: 255)]
    private string $rawLabel;

    /** Libellé nettoyé (« CB CARREFOUR 12/10 » → « Carrefour »). */
    #[ORM\Column(length: 120)]
    private string $label;

    /** Centimes, négatif pour un débit, positif pour un crédit. */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    /** Catégorie choisie au tri (sert aussi à apprendre les suggestions). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Expense $expense = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FixedExpense $fixedExpense = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Income $income = null;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $classifiedAt = null;

    public function __construct(User $user, string $hash, \DateTimeImmutable $date, string $rawLabel, string $label, int $amount)
    {
        $this->user = $user;
        $this->hash = $hash;
        $this->date = $date;
        $this->rawLabel = mb_substr($rawLabel, 0, 255);
        $this->label = mb_substr($label, 0, 120);
        $this->amount = $amount;
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getRawLabel(): string
    {
        return $this->rawLabel;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr($label, 0, 120);

        return $this;
    }

    /** Clé de rapprochement des libellés pour les suggestions. */
    public function getLabelKey(): string
    {
        return mb_strtoupper($this->label);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getExpense(): ?Expense
    {
        return $this->expense;
    }

    public function getFixedExpense(): ?FixedExpense
    {
        return $this->fixedExpense;
    }

    public function getIncome(): ?Income
    {
        return $this->income;
    }

    public function getClassifiedAt(): ?\DateTimeImmutable
    {
        return $this->classifiedAt;
    }

    /** Enregistre la décision prise au tri et ce qu'elle a créé. */
    public function classify(string $status, ?Category $category = null, ?Expense $expense = null, ?FixedExpense $fixed = null, ?Income $income = null): static
    {
        $this->status = $status;
        $this->category = $category;
        $this->expense = $expense;
        $this->fixedExpense = $fixed;
        $this->income = $income;
        $this->classifiedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Remet l'opération à trier (annulation). */
    public function reset(): static
    {
        $this->status = self::PENDING;
        $this->category = null;
        $this->expense = null;
        $this->fixedExpense = null;
        $this->income = null;
        $this->classifiedAt = null;

        return $this;
    }
}
