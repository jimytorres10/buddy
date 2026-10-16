<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MenuController extends AbstractController
{
    #[Route('/menu', name: 'app_menu')]
    public function index(): Response
    {
        return $this->render('menu/index.html.twig');
    }

    #[Route('/menu/wizard', name: 'app_menu_restart_wizard', methods: ['POST'])]
    public function restartWizard(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('restart_wizard', $request->getPayload()->getString('_token'))) {
            $user->setOnboardingCompleted(false);
            $em->flush();
        }

        return $this->redirectToRoute('app_onboarding', [], Response::HTTP_SEE_OTHER);
    }
}
