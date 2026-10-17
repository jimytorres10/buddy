<?php

namespace App\Service;

use App\Entity\User;

/**
 * Données des graphiques : répartition des revenus, budgets vs réel,
 * dépenses par catégorie et évolution sur plusieurs mois. Montants en centimes.
 */
class StatsReport
{
    public function __construct(private MonthSummary $summary)
    {
    }

    public function build(User $user, \DateTimeImmutable $month, int $trendMonths = 6): array
    {
        $summary = $this->summary->compute($user, $month);

        return [
            'summary' => $summary,
            'split' => $this->incomeSplit($summary),
            'budgets' => $this->budgetsVsActual($summary),
            'categories' => $this->spendingByCategory($summary),
            'trend' => $this->trend($user, $month, $trendMonths),
        ];
    }

    /**
     * Aperçu de l'impact d'un ajustement (budgets, dépenses fixes) sur la répartition d'un mois :
     * la répartition actuelle, plus les données que le navigateur recalcule pendant la saisie.
     */
    public function impact(User $user, \DateTimeImmutable $month): array
    {
        $summary = $this->summary->compute($user, $month);

        return [
            'month' => $month,
            'split' => $this->incomeSplit($summary),
            'data' => [
                'month' => $month->format('Y-m'),
                'income' => $summary['income'],
                'fixed' => $summary['fixed'],
                'spent' => $summary['spent'],
                'categories' => (object) $summary['byCategory'],
            ],
        ];
    }

    /**
     * Revenus découpés en fixes / dépensé / reste des budgets / épargne.
     * L'épargne est ce qui n'est ni dépensé ni réservé par un budget ; si les revenus
     * ne suffisent pas, le reste des budgets est rogné (shortfall) puis les sorties débordent (overflow).
     */
    private function incomeSplit(array $summary): array
    {
        $outgoing = $summary['fixed'] + $summary['spent'];
        $available = max(0, $summary['income'] - $outgoing);
        $budgetLeft = array_sum(array_map(fn (array $l) => max(0, $l['budget'] - $l['spent']), $summary['budgets']));
        $base = max($summary['income'], $outgoing, 1);

        $parts = [
            ['key' => 'fixed', 'label' => 'Dépenses fixes', 'amount' => $summary['fixed']],
            ['key' => 'spent', 'label' => 'Dépenses du mois', 'amount' => $summary['spent']],
            ['key' => 'left', 'label' => 'Reste des budgets', 'amount' => min($budgetLeft, $available)],
            ['key' => 'saving', 'label' => 'Épargne', 'amount' => max(0, $available - $budgetLeft)],
        ];

        foreach ($parts as &$part) {
            $part['percent'] = round($part['amount'] * 100 / $base, 1);
        }

        return [
            'parts' => $parts,
            'income' => $summary['income'],
            'overflow' => max(0, $outgoing - $summary['income']),
            // Part des budgets restants que les revenus ne couvrent pas
            'shortfall' => max(0, $budgetLeft - $available),
            // Part des revenus (sur la largeur totale) quand les sorties dépassent les revenus
            'incomePercent' => round($summary['income'] * 100 / $base, 1),
        ];
    }

    /** Une ligne par catégorie budgétée, à l'échelle de la plus grande valeur. */
    private function budgetsVsActual(array $summary): array
    {
        $max = max([1, ...array_column($summary['budgets'], 'budget'), ...array_column($summary['budgets'], 'spent')]);

        $lines = array_map(fn (array $line) => $line + [
            'budgetWidth' => round($line['budget'] * 100 / $max, 1),
            'spentWidth' => round($line['spent'] * 100 / $max, 1),
        ], $summary['budgets']);

        return [
            'lines' => $lines,
            'budgeted' => $summary['budgeted'],
            'spent' => array_sum(array_column($summary['budgets'], 'spent')),
        ];
    }

    /** Segments du donut des dépenses du mois, du plus gros au plus petit. */
    private function spendingByCategory(array $summary): array
    {
        $items = array_filter(
            array_map(fn (array $l) => ['category' => $l['category'], 'spent' => $l['spent']], [...$summary['budgets'], ...$summary['unbudgeted']]),
            fn (array $i) => $i['spent'] > 0,
        );
        usort($items, fn ($a, $b) => $b['spent'] <=> $a['spent']);

        $total = max(1, $summary['spent']);
        $offset = 0.0;
        foreach ($items as &$item) {
            $item['percent'] = round($item['spent'] * 100 / $total, 1);
            $item['offset'] = $offset;
            $offset += $item['spent'] * 100 / $total;
        }

        return ['items' => $items, 'total' => $summary['spent']];
    }

    /** Revenus vs sorties (fixes + dépenses) sur les N mois jusqu'au mois affiché. */
    private function trend(User $user, \DateTimeImmutable $month, int $count): array
    {
        $months = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            $m = $month->modify(\sprintf('-%d months', $i));
            $s = $this->summary->compute($user, $m);
            $months[] = ['month' => $m, 'income' => $s['income'], 'fixed' => $s['fixed'], 'spent' => $s['spent']];
        }

        $max = max([1, ...array_map(fn ($m) => max($m['income'], $m['fixed'] + $m['spent']), $months)]);
        foreach ($months as &$m) {
            $m['incomeHeight'] = round($m['income'] * 100 / $max, 1);
            $m['fixedHeight'] = round($m['fixed'] * 100 / $max, 1);
            $m['spentHeight'] = round($m['spent'] * 100 / $max, 1);
            $m['current'] = $m['month']->format('Y-m') === $month->format('Y-m');
        }

        return ['months' => $months, 'max' => $max];
    }
}
