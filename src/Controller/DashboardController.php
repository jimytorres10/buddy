<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ExpenseRepository;
use App\Service\MonthSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(#[CurrentUser] User $user, MonthSummary $summary, ExpenseRepository $expenses): Response
    {
        $month = new \DateTimeImmutable();

        return $this->render('dashboard/index.html.twig', [
            'month' => $month,
            'summary' => $summary->compute($user, $month),
            'latest' => $expenses->findLatest($user, 3),
        ]);
    }
}
