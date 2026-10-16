<?php

namespace App\Repository;

use App\Entity\Budget;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Budget>
 */
class BudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Budget::class);
    }

    /**
     * Budgets de l'utilisateur indexés par id de catégorie.
     *
     * @return array<int, Budget>
     */
    public function findForUserIndexedByCategory(User $user): array
    {
        $budgets = $this->createQueryBuilder('b')
            ->addSelect('c')
            ->join('b.category', 'c')
            ->andWhere('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($budgets as $budget) {
            $indexed[$budget->getCategory()->getId()] = $budget;
        }

        return $indexed;
    }
}
