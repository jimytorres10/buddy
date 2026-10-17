<?php

namespace App\Controller;

use App\Entity\Expense;
use App\Entity\User;
use App\Form\ExpenseType;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\ImportedTransactionRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/depenses')]
class ExpenseController extends AbstractController
{
    #[Route('', name: 'app_expense_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, ExpenseRepository $expenses, CategoryRepository $categories): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('mois')) ?: new \DateTimeImmutable('first day of this month midnight');

        $categoryId = $request->query->getInt('categorie');
        $category = $categoryId ? $categories->findOneBy(['id' => $categoryId, 'user' => $user]) : null;

        $list = $expenses->findForMonth($user, $month, $category);

        $days = [];
        foreach ($list as $expense) {
            $key = $expense->getDate()->format('Y-m-d');
            $days[$key]['date'] ??= $expense->getDate();
            $days[$key]['total'] = ($days[$key]['total'] ?? 0) + $expense->getAmount();
            $days[$key]['items'][] = $expense;
        }

        return $this->render('expense/index.html.twig', [
            'month' => $month,
            'previous' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'isCurrentMonth' => $month->format('Y-m') === date('Y-m'),
            'days' => $days,
            'total' => array_sum(array_map(fn (Expense $e) => $e->getAmount(), $list)),
            'count' => \count($list),
            'categories' => $categories->findForUser($user),
            'currentCategory' => $category,
        ]);
    }

    #[Route('/nouvelle', name: 'app_expense_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        $expense = (new Expense())->setUser($user);
        $form = $this->createForm(ExpenseType::class, $expense, ['user' => $user]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($expense);
            $em->flush();
            $this->addFlash('success', 'Dépense ajoutée.');

            return $this->redirectToRoute('app_expense_index', ['mois' => $expense->getDate()->format('Y-m')]);
        }

        return $this->render('expense/form.html.twig', ['form' => $form, 'expense' => $expense]);
    }

    /** Détail déplié dans la liste (Turbo Frame) ; hors liste, on renvoie vers la page de modification. */
    #[Route('/{id}', name: 'app_expense_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(OwnerVoter::MANAGE, 'expense')]
    public function show(Expense $expense, Request $request, ImportedTransactionRepository $imports): Response
    {
        if (!$this->isFrame($request, $expense)) {
            return $this->redirectToRoute('app_expense_edit', ['id' => $expense->getId()]);
        }

        return $this->render('expense/_detail.html.twig', [
            'expense' => $expense,
            'imported' => $imports->findOneBy(['expense' => $expense]),
        ]);
    }

    /** Ligne repliée. */
    #[Route('/{id}/ligne', name: 'app_expense_row', methods: ['GET'])]
    #[IsGranted(OwnerVoter::MANAGE, 'expense')]
    public function row(Expense $expense): Response
    {
        return $this->render('expense/_row.html.twig', ['expense' => $expense]);
    }

    #[Route('/{id}/modifier', name: 'app_expense_edit', methods: ['GET', 'POST'])]
    #[IsGranted(OwnerVoter::MANAGE, 'expense')]
    public function edit(Expense $expense, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        $inline = $this->isFrame($request, $expense);
        $form = $this->createForm(ExpenseType::class, $expense, ['user' => $user, 'inline' => $inline]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Dépense modifiée.');

            return $inline ? $this->refreshPage() : $this->redirectToRoute('app_expense_index', ['mois' => $expense->getDate()->format('Y-m')]);
        }

        return $this->render($inline ? 'expense/_inline_form.html.twig' : 'expense/form.html.twig', ['form' => $form, 'expense' => $expense]);
    }

    #[Route('/{id}/supprimer', name: 'app_expense_delete', methods: ['POST'])]
    #[IsGranted(OwnerVoter::MANAGE, 'expense')]
    public function delete(Expense $expense, Request $request, EntityManagerInterface $em): Response
    {
        $month = $expense->getDate()->format('Y-m');
        $inline = $this->isFrame($request, $expense);

        if ($this->isCsrfTokenValid('delete'.$expense->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($expense);
            $em->flush();
            $this->addFlash('success', 'Dépense supprimée.');
        }

        return $inline ? $this->refreshPage() : $this->redirectToRoute('app_expense_index', ['mois' => $month], Response::HTTP_SEE_OTHER);
    }

    /** La requête vient-elle du cadre de cette dépense dans la liste ? */
    private function isFrame(Request $request, Expense $expense): bool
    {
        return $request->headers->get('Turbo-Frame') === 'expense_'.$expense->getId();
    }

    /** Recharge la liste (morphing Turbo) : totaux du jour et du mois à jour, position conservée. */
    private function refreshPage(): Response
    {
        return new Response('<turbo-stream action="refresh"></turbo-stream>', Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']);
    }
}
