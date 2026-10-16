<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expense>
 */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /** @return list<Expense> */
    public function findForMonth(User $user, \DateTimeImmutable $month, ?Category $category = null): array
    {
        $qb = $this->monthQuery($user, $month)
            ->addSelect('c')
            ->join('e.category', 'c')
            ->orderBy('e.date', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if ($category) {
            $qb->andWhere('e.category = :category')->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Expense> */
    public function findLatest(User $user, int $limit = 3): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('c')
            ->join('e.category', 'c')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.date', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Total dépensé par catégorie sur le mois, en centimes.
     *
     * @return array<int, int> id de catégorie => total
     */
    public function sumByCategoryForMonth(User $user, \DateTimeImmutable $month): array
    {
        $rows = $this->monthQuery($user, $month)
            ->select('IDENTITY(e.category) AS category, SUM(e.amount) AS total')
            ->groupBy('e.category')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['category']] = (int) $row['total'];
        }

        return $totals;
    }

    private function monthQuery(User $user, \DateTimeImmutable $month): QueryBuilder
    {
        $start = $month->modify('first day of this month')->setTime(0, 0);

        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->andWhere('e.date >= :start AND e.date < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start, 'date_immutable')
            ->setParameter('end', $start->modify('+1 month'), 'date_immutable');
    }
}
