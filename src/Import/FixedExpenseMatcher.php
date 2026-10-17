<?php

namespace App\Import;

use App\Entity\FixedExpense;
use App\Entity\ImportedTransaction;
use App\Repository\FixedExpenseRepository;
use App\Repository\ImportedTransactionRepository;

/**
 * Retrouve à quelle dépense fixe existante peut correspondre une opération importée.
 * Indices, du plus fiable au moins fiable :
 *  - « learned » : ce libellé a déjà été pointé / créé sur cette dépense fixe ;
 *  - « label »   : le nom de la dépense fixe apparaît dans le libellé bancaire ;
 *  - « amount »  : même montant (à 2 % près).
 */
class FixedExpenseMatcher
{
    public function __construct(
        private FixedExpenseRepository $fixedExpenses,
        private ImportedTransactionRepository $transactions,
    ) {
    }

    /**
     * @return array{
     *     candidates: list<array{fixed: FixedExpense, reason: string, pointed: ?ImportedTransaction}>,
     *     all: list<array{fixed: FixedExpense, pointed: ?ImportedTransaction}>
     * }
     */
    public function match(ImportedTransaction $t): array
    {
        if ($t->isCredit()) {
            return ['candidates' => [], 'all' => []];
        }

        $user = $t->getUser();
        $active = array_values(array_filter($this->fixedExpenses->findForUser($user), fn (FixedExpense $f) => $f->isActiveIn($t->getDate())));
        $pointed = $this->transactions->findPointedFixedForMonth($user, $t->getDate());
        $learned = $this->transactions->findLearnedFixedExpense($user, $t->getLabel());

        $haystack = $this->normalize($t->getRawLabel().' '.$t->getLabel());
        $amount = abs($t->getAmount());
        $rank = ['learned' => 0, 'label' => 1, 'amount' => 2];

        $candidates = [];
        foreach ($active as $fixed) {
            $reason = match (true) {
                $learned && $learned->getId() === $fixed->getId() => 'learned',
                $this->labelMatches($this->normalize((string) $fixed->getLabel()), $haystack) => 'label',
                abs($fixed->getAmount() - $amount) <= max(1, (int) round($fixed->getAmount() * 0.02)) => 'amount',
                default => null,
            };
            if ($reason) {
                $candidates[] = ['fixed' => $fixed, 'reason' => $reason, 'pointed' => $pointed[$fixed->getId()] ?? null];
            }
        }

        // Les plus fiables d'abord, puis celles pas encore pointées ce mois-ci
        usort($candidates, fn ($a, $b) => [$rank[$a['reason']], null !== $a['pointed']] <=> [$rank[$b['reason']], null !== $b['pointed']]);

        return [
            'candidates' => \array_slice($candidates, 0, 3),
            'all' => array_map(fn (FixedExpense $f) => ['fixed' => $f, 'pointed' => $pointed[$f->getId()] ?? null], $active),
        ];
    }

    /** Un mot significatif (≥ 4 lettres) du nom de la dépense fixe apparaît dans le libellé. */
    private function labelMatches(string $name, string $haystack): bool
    {
        foreach (explode(' ', $name) as $word) {
            if (mb_strlen($word) >= 4 && str_contains($haystack, ' '.$word)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = strtr(mb_strtoupper($value), ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ç' => 'C', 'Ô' => 'O', 'Î' => 'I', 'Û' => 'U']);

        return ' '.trim(preg_replace('/[^A-Z0-9]+/', ' ', $value)).' ';
    }
}
