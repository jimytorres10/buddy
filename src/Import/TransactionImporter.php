<?php

namespace App\Import;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\FixedExpense;
use App\Entity\ImportedTransaction;
use App\Entity\Income;
use App\Entity\User;
use App\Repository\ImportedTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import d'un fichier bancaire puis application des décisions prises au tri.
 */
class TransactionImporter
{
    public function __construct(
        private StatementParser $parser,
        private LabelCleaner $cleaner,
        private ImportedTransactionRepository $transactions,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{added: int, duplicates: int}
     *
     * @throws \InvalidArgumentException fichier illisible
     */
    public function import(User $user, string $content, string $filename): array
    {
        $operations = $this->parser->parse($content, $filename);

        // Empreinte stable : l'identifiant bancaire (OFX) ou date + montant + libellé + rang
        // (deux cafés identiques le même jour restent deux opérations distinctes).
        $occurrences = [];
        foreach ($operations as &$op) {
            $key = $op['date']->format('Y-m-d').'|'.$op['amount'].'|'.mb_strtoupper(preg_replace('/\s+/u', ' ', $op['label']));
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            $op['hash'] = hash('sha256', $op['id'] ? 'id|'.$op['id'] : $key.'|'.$occurrences[$key]);
        }
        unset($op);

        $known = $this->transactions->findExistingHashes($user, array_column($operations, 'hash'));
        $added = 0;
        foreach ($operations as $op) {
            if (isset($known[$op['hash']])) {
                continue;
            }
            $known[$op['hash']] = true;
            $this->em->persist(new ImportedTransaction($user, $op['hash'], $op['date'], $op['label'], $this->cleaner->clean($op['label']), $op['amount']));
            ++$added;
        }
        $this->em->flush();

        return ['added' => $added, 'duplicates' => \count($operations) - $added];
    }

    public function toExpense(ImportedTransaction $t, Category $category, string $label): void
    {
        $expense = (new Expense())
            ->setUser($t->getUser())
            ->setCategory($category)
            ->setAmount(abs($t->getAmount()))
            ->setDate($t->getDate())
            ->setLabel($label);
        $this->em->persist($expense);
        $t->setLabel($label)->classify(ImportedTransaction::EXPENSE, $category, expense: $expense);
        $this->em->flush();
    }

    /** Dépense fixe à partir du mois de l'opération : tous les mois, ou pendant $months mois. */
    public function toFixedExpense(ImportedTransaction $t, ?Category $category, string $label, ?int $months): void
    {
        $start = $t->getDate()->modify('first day of this month midnight');
        $fixed = (new FixedExpense())
            ->setUser($t->getUser())
            ->setLabel(mb_substr($label, 0, 80))
            ->setAmount(abs($t->getAmount()))
            ->setCategory($category)
            ->setStartMonth($start)
            ->setEndMonth($months ? $start->modify(\sprintf('+%d months', $months - 1)) : null);
        $this->em->persist($fixed);
        $t->setLabel($label)->classify(ImportedTransaction::FIXED, $category, fixed: $fixed);
        $this->em->flush();
    }

    public function toIncome(ImportedTransaction $t, string $label): void
    {
        $income = (new Income())->setUser($t->getUser())->setLabel(mb_substr($label, 0, 80))->setAmount($t->getAmount());
        $this->em->persist($income);
        $t->setLabel($label)->classify(ImportedTransaction::INCOME, income: $income);
        $this->em->flush();
    }

    public function ignore(ImportedTransaction $t): void
    {
        $t->classify(ImportedTransaction::IGNORED);
        $this->em->flush();
    }

    /** Annule la décision : supprime ce qui avait été créé et remet l'opération à trier. */
    public function undo(ImportedTransaction $t): void
    {
        foreach ([$t->getExpense(), $t->getFixedExpense(), $t->getIncome()] as $created) {
            if ($created) {
                $this->em->remove($created);
            }
        }
        $t->reset();
        $this->em->flush();
    }
}
