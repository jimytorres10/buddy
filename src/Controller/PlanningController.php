<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\MonthlyBudget;
use App\Entity\User;
use App\Form\CategoryType;
use App\Form\MonthBudgetsType;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\ImportedTransactionRepository;
use App\Repository\MonthlyBudgetRepository;
use App\Repository\ExpenseRepository;
use App\Repository\FixedExpenseRepository;
use App\Repository\IncomeRepository;
use App\Security\OwnerVoter;
use App\Service\MonthSummary;
use App\Service\PlanningForms;
use App\Service\StatsReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/budget')]
class PlanningController extends AbstractController
{
    private const TITLES = [
        'revenus' => 'Mes revenus',
        'depenses-fixes' => 'Mes dépenses fixes',
        'budgets' => 'Mes budgets',
    ];

    #[Route('', name: 'app_planning')]
    public function index(
        #[CurrentUser] User $user,
        MonthSummary $summary,
        IncomeRepository $incomes,
        FixedExpenseRepository $fixedExpenses,
        ImportedTransactionRepository $imports,
    ): Response {
        $now = new \DateTimeImmutable();

        return $this->render('planning/index.html.twig', [
            'summary' => $summary->compute($user, $now),
            'incomes' => $incomes->findForUser($user),
            'fixedExpenses' => $fixedExpenses->findForUser($user),
            // Pointage du mois (seulement si l'utilisateur importe ses relevés)
            'pointed' => $imports->findPointedFixedForMonth($user, $now),
            'usesImports' => $imports->count(['user' => $user]) > 0,
        ]);
    }

    #[Route('/categories', name: 'app_category_index')]
    public function categories(#[CurrentUser] User $user, CategoryRepository $categories): Response
    {
        return $this->render('planning/categories.html.twig', ['categories' => $categories->findForUser($user)]);
    }

    #[Route('/categories/nouvelle', name: 'app_category_new')]
    public function newCategory(Request $request, #[CurrentUser] User $user, CategoryRepository $categories, EntityManagerInterface $em): Response
    {
        $category = (new Category())->setUser($user)->setPosition(\count($categories->findForUser($user)));

        return $this->categoryForm($category, $request, $em);
    }

    #[Route('/categories/{id}', name: 'app_category_edit', requirements: ['id' => '\d+'])]
    #[IsGranted(OwnerVoter::MANAGE, 'category')]
    public function editCategory(Category $category, Request $request, EntityManagerInterface $em, ExpenseRepository $expenses): Response
    {
        return $this->categoryForm($category, $request, $em, $expenses->count(['category' => $category]));
    }

    #[Route('/categories/{id}/supprimer', name: 'app_category_delete', methods: ['POST'])]
    #[IsGranted(OwnerVoter::MANAGE, 'category')]
    public function deleteCategory(Category $category, Request $request, EntityManagerInterface $em, ExpenseRepository $expenses): Response
    {
        if (!$this->isCsrfTokenValid('delete-category'.$category->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($expenses->count(['category' => $category]) > 0) {
            $this->addFlash('error', 'Cette catégorie contient des dépenses : déplace-les avant de la supprimer.');
        } else {
            $em->remove($category);
            $em->flush();
            $this->addFlash('success', 'Catégorie supprimée.');
        }

        return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mois/{mois?}', name: 'app_budget_month', requirements: ['mois' => '\d{4}-\d{2}'])]
    public function month(
        ?string $mois,
        Request $request,
        #[CurrentUser] User $user,
        CategoryRepository $categories,
        BudgetRepository $budgets,
        MonthlyBudgetRepository $monthlyBudgets,
        ExpenseRepository $expenses,
        EntityManagerInterface $em,
        StatsReport $report,
    ): Response {
        $month = ($mois ? \DateTimeImmutable::createFromFormat('!Y-m', $mois) : null) ?: new \DateTimeImmutable('first day of this month midnight');
        $categoryList = $categories->findForUser($user);
        $adjustments = $monthlyBudgets->findForMonthIndexedByCategory($user, $month);

        $data = [];
        foreach ($categoryList as $category) {
            $data['c'.$category->getId()] = isset($adjustments[$category->getId()]) ? $adjustments[$category->getId()]->getAmount() : null;
        }
        $form = $this->createForm(MonthBudgetsType::class, $data, ['categories' => $categoryList]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reset = $request->request->has('reset');
            foreach ($categoryList as $category) {
                $amount = $reset ? null : $form->get('c'.$category->getId())->getData();
                $adjustment = $adjustments[$category->getId()] ?? null;

                if (null === $amount) {
                    if ($adjustment) {
                        $em->remove($adjustment);
                    }
                    continue;
                }

                $adjustment ??= (new MonthlyBudget())->setUser($user)->setCategory($category)->setMonth($month);
                $em->persist($adjustment->setAmount($amount));
            }
            $em->flush();
            $this->addFlash('success', $reset ? 'Budgets habituels rétablis pour ce mois.' : 'Budgets du mois enregistrés.');

            return $this->redirectToRoute('app_budget_month', ['mois' => $month->format('Y-m')]);
        }

        return $this->render('planning/month.html.twig', [
            'form' => $form,
            'month' => $month,
            'previous' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'isCurrentMonth' => $month->format('Y-m') === date('Y-m'),
            'categories' => $categoryList,
            'usual' => $budgets->findForUserIndexedByCategory($user),
            'adjusted' => $adjustments,
            'spent' => $expenses->sumByCategoryForMonth($user, $month),
            'impact' => $report->impact($user, $month),
        ]);
    }

    #[Route('/{section}', name: 'app_planning_edit', requirements: ['section' => 'revenus|depenses-fixes|budgets'])]
    public function edit(string $section, Request $request, #[CurrentUser] User $user, PlanningForms $planning, StatsReport $report): Response
    {
        $result = $planning->handle($section, $user, $request);

        if ($result['saved']) {
            $this->addFlash('success', 'Modifications enregistrées.');

            return $this->redirectToRoute('app_planning');
        }

        return $this->render('planning/edit.html.twig', [
            'section' => $section,
            'title' => self::TITLES[$section],
            'form' => $result['form'],
            'categories' => $result['categories'],
            // Aperçu de l'impact sur le mois en cours (pas pour les revenus)
            'impact' => 'revenus' === $section ? null : $report->impact($user, new \DateTimeImmutable('first day of this month midnight')),
        ]);
    }

    private function categoryForm(Category $category, Request $request, EntityManagerInterface $em, int $expenseCount = 0): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', 'Catégorie enregistrée.');

            return $this->redirectToRoute('app_category_index');
        }

        return $this->render('planning/category_form.html.twig', [
            'form' => $form,
            'category' => $category,
            'expenseCount' => $expenseCount,
        ]);
    }
}
