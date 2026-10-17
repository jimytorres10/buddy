<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\StatsReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class StatsController extends AbstractController
{
    #[Route('/graphiques', name: 'app_stats')]
    public function index(Request $request, #[CurrentUser] User $user, StatsReport $report): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('mois')) ?: new \DateTimeImmutable('first day of this month midnight');

        return $this->render('stats/index.html.twig', [
            'month' => $month,
            'previous' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'isCurrentMonth' => $month->format('Y-m') === date('Y-m'),
        ] + $report->build($user, $month));
    }
}
