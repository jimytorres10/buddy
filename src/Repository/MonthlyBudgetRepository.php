<?php

namespace App\Repository;

use App\Entity\MonthlyBudget;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonthlyBudget>
 */
class MonthlyBudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonthlyBudget::class);
    }

    /**
     * Ajustements du mois indexés par id de catégorie.
     *
     * @return array<int, MonthlyBudget>
     */
    public function findForMonthIndexedByCategory(User $user, \DateTimeImmutable $month): array
    {
        $rows = $this->createQueryBuilder('m')
            ->addSelect('c')
            ->join('m.category', 'c')
            ->andWhere('m.user = :user')
            ->andWhere('m.month = :month')
            ->setParameter('user', $user)
            ->setParameter('month', $month->modify('first day of this month midnight'), 'date_immutable')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->getCategory()->getId()] = $row;
        }

        return $indexed;
    }
}
