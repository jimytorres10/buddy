<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\FixedExpenseRepository;
use App\Repository\IncomeRepository;
use App\Repository\MonthlyBudgetRepository;

/**
 * Calcule la synthèse d'un mois : revenus, charges fixes, dépenses et budgets.
 * Tous les montants sont en centimes.
 */
class MonthSummary
{
    public function __construct(
        private IncomeRepository $incomes,
        private FixedExpenseRepository $fixedExpenses,
        private ExpenseRepository $expenses,
        private BudgetRepository $budgets,
        private CategoryRepository $categories,
        private MonthlyBudgetRepository $monthlyBudgets,
    ) {
    }

    /**
     * @return array{
     *     income: int, fixed: int, spent: int, remaining: int, budgeted: int,
     *     budgets: list<array{category: \App\Entity\Category, budget: int, usual: ?int, adjusted: bool, spent: int, percent: int, over: bool}>,
     *     unbudgeted: list<array{category: \App\Entity\Category, spent: int}>
     * }
     */
    public function compute(User $user, \DateTimeImmutable $month): array
    {
        $income = array_sum(array_map(fn ($i) => $i->getAmount(), $this->incomes->findForUser($user)));
        $fixed = array_sum(array_map(
            fn ($f) => $f->getAmount(),
            array_filter($this->fixedExpenses->findForUser($user), fn ($f) => $f->isActiveIn($month)),
        ));
        $spentByCategory = $this->expenses->sumByCategoryForMonth($user, $month);
        $budgets = $this->budgets->findForUserIndexedByCategory($user);
        $adjustments = $this->monthlyBudgets->findForMonthIndexedByCategory($user, $month);

        $budgetLines = [];
        $unbudgeted = [];
        foreach ($this->categories->findForUser($user) as $category) {
            $spent = $spentByCategory[$category->getId()] ?? 0;
            $usual = isset($budgets[$category->getId()]) ? $budgets[$category->getId()]->getAmount() : null;
            $adjusted = isset($adjustments[$category->getId()]);
            // Un ajustement du mois remplace le budget habituel ; 0 = pas de budget ce mois-ci
            $amount = $adjusted ? $adjustments[$category->getId()]->getAmount() : $usual;

            if ($amount) {
                $budgetLines[] = [
                    'category' => $category,
                    'budget' => $amount,
                    'usual' => $usual,
                    'adjusted' => $adjusted,
                    'spent' => $spent,
                    'percent' => (int) min(100, round($spent * 100 / $amount)),
                    'over' => $spent > $amount,
                ];
            } elseif ($spent > 0) {
                $unbudgeted[] = ['category' => $category, 'spent' => $spent];
            }
        }

        $spent = array_sum($spentByCategory);

        return [
            'income' => $income,
            'fixed' => $fixed,
            'spent' => $spent,
            'remaining' => $income - $fixed - $spent,
            'budgeted' => array_sum(array_column($budgetLines, 'budget')),
            'budgets' => $budgetLines,
            'unbudgeted' => $unbudgeted,
        ];
    }
}
