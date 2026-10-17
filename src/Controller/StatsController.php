<?php

namespace App\Controller;

use App\Entity\FixedExpense;
use App\Entity\User;
use App\Form\FixedExpenseType;
use App\Repository\FixedExpenseRepository;
use App\Repository\ImportedTransactionRepository;
use App\Service\StatsReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class StatsController extends AbstractController
{
    public function __construct(
        private StatsReport $report,
        private FixedExpenseRepository $fixedExpenses,
        private ImportedTransactionRepository $imports,
    ) {
    }

    #[Route('/graphiques', name: 'app_stats')]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('mois')) ?: new \DateTimeImmutable('first day of this month midnight');

        return $this->renderStats($user, $month, $this->fixedForm($user, $month));
    }

    /** Ajout rapide d'une dépense fixe depuis les graphiques : elle commence au mois affiché. */
    #[Route('/graphiques/{mois}/depense-fixe', name: 'app_stats_fixed_new', requirements: ['mois' => '\d{4}-\d{2}'], methods: ['POST'])]
    public function newFixed(string $mois, Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $mois);
        $form = $this->fixedForm($user, $month)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fixed = $form->getData();
            $fixed->setStartMonth($fixed->getStartMonth() ?? $month);
            $em->persist($fixed);
            $em->flush();
            $this->addFlash('success', \sprintf('Dépense fixe « %s » ajoutée.', $fixed->getLabel()));

            return $this->redirectToRoute('app_stats', ['mois' => $mois], Response::HTTP_SEE_OTHER);
        }

        return $this->renderStats($user, $month, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function fixedForm(User $user, \DateTimeImmutable $month): FormInterface
    {
        $fixed = (new FixedExpense())->setUser($user)->setStartMonth($month);

        return $this->createForm(FixedExpenseType::class, $fixed, [
            'user' => $user,
            'action' => $this->generateUrl('app_stats_fixed_new', ['mois' => $month->format('Y-m')]),
        ]);
    }

    private function renderStats(User $user, \DateTimeImmutable $month, FormInterface $form, int $status = 200): Response
    {
        $fixed = array_values(array_filter($this->fixedExpenses->findForUser($user), fn (FixedExpense $f) => $f->isActiveIn($month)));

        return $this->render('stats/index.html.twig', [
            'month' => $month,
            'previous' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'isCurrentMonth' => $month->format('Y-m') === date('Y-m'),
            'fixedExpenses' => $fixed,
            'pointed' => $this->imports->findPointedFixedForMonth($user, $month),
            'fixedForm' => $form->createView(),
        ] + $this->report->build($user, $month), new Response(status: $status));
    }
}
