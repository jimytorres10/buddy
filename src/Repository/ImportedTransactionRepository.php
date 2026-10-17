<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\FixedExpense;
use App\Entity\ImportedTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportedTransaction>
 */
class ImportedTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportedTransaction::class);
    }

    /** Prochaine opération à trier (la plus ancienne d'abord). */
    public function findNextPending(User $user): ?ImportedTransaction
    {
        return $this->findOneBy(['user' => $user, 'status' => ImportedTransaction::PENDING], ['date' => 'ASC', 'id' => 'ASC']);
    }

    public function countPending(User $user): int
    {
        return $this->count(['user' => $user, 'status' => ImportedTransaction::PENDING]);
    }

    /** Dernière opération classée (pour « Annuler »). */
    public function findLastClassified(User $user): ?ImportedTransaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.status != :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', ImportedTransaction::PENDING)
            ->orderBy('t.classifiedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Empreintes déjà connues parmi celles proposées.
     *
     * @param list<string> $hashes
     *
     * @return array<string, true>
     */
    public function findExistingHashes(User $user, array $hashes): array
    {
        if (!$hashes) {
            return [];
        }
        $rows = $this->createQueryBuilder('t')
            ->select('t.hash')
            ->andWhere('t.user = :user')
            ->andWhere('t.hash IN (:hashes)')
            ->setParameter('user', $user)
            ->setParameter('hashes', $hashes)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($rows, true);
    }

    /** Catégorie choisie la dernière fois pour un même libellé nettoyé. */
    public function findLearnedCategory(User $user, string $label): ?Category
    {
        $row = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.label = :label')
            ->andWhere('t.category IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('label', $label)
            ->orderBy('t.classifiedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row?->getCategory();
    }

    /** Dépense fixe sur laquelle un même libellé a déjà été pointé ou créé. */
    public function findLearnedFixedExpense(User $user, string $label): ?FixedExpense
    {
        $row = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.label = :label')
            ->andWhere('t.fixedExpense IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('label', $label)
            ->orderBy('t.classifiedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row?->getFixedExpense();
    }

    /**
     * Opérations rattachées à une dépense fixe pour le mois donné, indexées par id de dépense fixe.
     *
     * @return array<int, ImportedTransaction>
     */
    public function findPointedFixedForMonth(User $user, \DateTimeImmutable $month): array
    {
        $start = $month->modify('first day of this month midnight');
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.fixedExpense IS NOT NULL')
            ->andWhere('t.date >= :start AND t.date < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start, 'date_immutable')
            ->setParameter('end', $start->modify('+1 month'), 'date_immutable')
            ->orderBy('t.date', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->getFixedExpense()->getId()] ??= $row;
        }

        return $indexed;
    }

    /** Nombre d'opérations par statut. @return array<string, int> */
    public function countByStatus(User $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS n')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'n', 'status');
    }
}
