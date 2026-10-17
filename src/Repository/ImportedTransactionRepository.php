<?php

namespace App\Repository;

use App\Entity\Category;
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
