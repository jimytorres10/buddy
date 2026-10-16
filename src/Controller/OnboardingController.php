<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\MonthSummary;
use App\Service\PlanningForms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/onboarding')]
class OnboardingController extends AbstractController
{
    /** Étapes du wizard, dans l'ordre. */
    private const STEPS = [
        'revenus' => 'Tes revenus',
        'depenses-fixes' => 'Tes dépenses fixes',
        'budgets' => 'Tes budgets',
        'recap' => 'Récapitulatif',
    ];

    #[Route('', name: 'app_onboarding')]
    public function start(): Response
    {
        return $this->redirectToRoute('app_onboarding_step', ['step' => 'revenus']);
    }

    #[Route('/{step}', name: 'app_onboarding_step', requirements: ['step' => 'revenus|depenses-fixes|budgets|recap'])]
    public function step(string $step, Request $request, #[CurrentUser] User $user, PlanningForms $planning, MonthSummary $summary): Response
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($step, $keys, true);
        $context = [
            'step' => $step,
            'steps' => self::STEPS,
            'index' => $index,
            'previous' => $keys[$index - 1] ?? null,
        ];

        if ('recap' === $step) {
            return $this->render('onboarding/recap.html.twig', $context + [
                'summary' => $summary->compute($user, new \DateTimeImmutable()),
            ]);
        }

        $result = $planning->handle($step, $user, $request);
        if ($result['saved']) {
            return $this->redirectToRoute('app_onboarding_step', ['step' => $keys[$index + 1]]);
        }

        return $this->render('onboarding/step.html.twig', $context + [
            'form' => $result['form'],
            'categories' => $result['categories'],
        ]);
    }

    #[Route('/terminer', name: 'app_onboarding_finish', methods: ['POST'], priority: 1)]
    public function finish(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('onboarding_finish', $request->getPayload()->getString('_token'))) {
            $user->setOnboardingCompleted(true);
            $em->flush();
            $this->addFlash('success', 'Ton budget est prêt. Bienvenue sur Buddy !');
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
