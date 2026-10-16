<?php

namespace App\Service;

use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\FixedExpense;
use App\Entity\Income;
use App\Entity\User;
use App\Form\BudgetsType;
use App\Form\FixedExpenseType;
use App\Form\IncomeType;
use App\Form\RecurringItemsType;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\FixedExpenseRepository;
use App\Repository\IncomeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Formulaires de planification (revenus, dépenses fixes, budgets) partagés
 * entre le wizard d'onboarding et les écrans de modification.
 */
class PlanningForms
{
    public const SECTIONS = ['revenus', 'depenses-fixes', 'budgets'];

    public function __construct(
        private FormFactoryInterface $forms,
        private EntityManagerInterface $em,
        private IncomeRepository $incomes,
        private FixedExpenseRepository $fixedExpenses,
        private BudgetRepository $budgets,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * Construit le formulaire de la section, traite la requête et enregistre si valide.
     *
     * @return array{form: FormInterface, saved: bool, categories: list<\App\Entity\Category>}
     */
    public function handle(string $section, User $user, Request $request): array
    {
        $categories = $this->categories->findForUser($user);

        $form = match ($section) {
            'revenus' => $this->itemsForm(IncomeType::class, [], $this->incomes->findForUser($user)),
            'depenses-fixes' => $this->itemsForm(FixedExpenseType::class, ['user' => $user], $this->fixedExpenses->findForUser($user)),
            'budgets' => $this->budgetsForm($user, $categories),
        };

        $form->handleRequest($request);
        $saved = false;

        if ($form->isSubmitted() && $form->isValid()) {
            match ($section) {
                'revenus' => $this->syncItems($user, $this->incomes->findForUser($user), $form->get('items')->getData()),
                'depenses-fixes' => $this->syncItems($user, $this->fixedExpenses->findForUser($user), $form->get('items')->getData()),
                'budgets' => $this->saveBudgets($user, $categories, $form->getData()),
            };
            $this->em->flush();
            $saved = true;
        }

        return ['form' => $form, 'saved' => $saved, 'categories' => $categories];
    }

    /** @param list<Income|FixedExpense> $existing */
    private function itemsForm(string $entryType, array $entryOptions, array $existing): FormInterface
    {
        $data = ['items' => $existing ?: [new ($entryType === IncomeType::class ? Income::class : FixedExpense::class)()]];

        return $this->forms->create(RecurringItemsType::class, $data, [
            'entry_type' => $entryType,
            'entry_options' => $entryOptions,
        ]);
    }

    /** @param list<\App\Entity\Category> $categories */
    private function budgetsForm(User $user, array $categories): FormInterface
    {
        $budgets = $this->budgets->findForUserIndexedByCategory($user);
        $data = [];
        foreach ($categories as $category) {
            $data['c'.$category->getId()] = isset($budgets[$category->getId()]) ? $budgets[$category->getId()]->getAmount() : null;
        }
        $data['newCategories'] = [];

        return $this->forms->create(BudgetsType::class, $data, ['categories' => $categories]);
    }

    /**
     * @param list<Income|FixedExpense> $existing
     * @param iterable<Income|FixedExpense> $submitted
     */
    private function syncItems(User $user, array $existing, iterable $submitted): void
    {
        $kept = [];
        foreach ($submitted as $item) {
            $item->setUser($user);
            $this->em->persist($item);
            $kept[spl_object_id($item)] = true;
        }
        foreach ($existing as $item) {
            if (!isset($kept[spl_object_id($item)])) {
                $this->em->remove($item);
            }
        }
    }

    /**
     * @param list<\App\Entity\Category> $categories
     * @param array<string, int|null> $data
     */
    private function saveBudgets(User $user, array $categories, array $data): void
    {
        $budgets = $this->budgets->findForUserIndexedByCategory($user);

        foreach ($categories as $category) {
            $amount = $data['c'.$category->getId()] ?? null;
            $budget = $budgets[$category->getId()] ?? null;

            if (!$amount) {
                if ($budget) {
                    $this->em->remove($budget);
                }
                continue;
            }

            $budget ??= (new Budget())->setUser($user)->setCategory($category);
            $budget->setAmount($amount);
            $this->em->persist($budget);
        }

        // Catégories personnalisées créées depuis cet écran
        $position = \count($categories);
        foreach ($data['newCategories'] ?? [] as $row) {
            $category = (new Category())
                ->setUser($user)
                ->setName(trim($row['name']))
                ->setIcon($row['icon'] ?: 'tag')
                ->setColor(Category::COLORS[$position % \count(Category::COLORS)])
                ->setPosition($position++);
            $this->em->persist($category);

            if ($row['amount'] ?? null) {
                $this->em->persist((new Budget())->setUser($user)->setCategory($category)->setAmount($row['amount']));
            }
        }
    }
}
