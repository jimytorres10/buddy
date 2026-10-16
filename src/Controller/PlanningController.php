<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\User;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\FixedExpenseRepository;
use App\Repository\IncomeRepository;
use App\Security\OwnerVoter;
use App\Service\MonthSummary;
use App\Service\PlanningForms;
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
    ): Response {
        return $this->render('planning/index.html.twig', [
            'summary' => $summary->compute($user, new \DateTimeImmutable()),
            'incomes' => $incomes->findForUser($user),
            'fixedExpenses' => $fixedExpenses->findForUser($user),
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

    #[Route('/{section}', name: 'app_planning_edit', requirements: ['section' => 'revenus|depenses-fixes|budgets'])]
    public function edit(string $section, Request $request, #[CurrentUser] User $user, PlanningForms $planning): Response
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
