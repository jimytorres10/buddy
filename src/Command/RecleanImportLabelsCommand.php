<?php

namespace App\Command;

use App\Import\LabelCleaner;
use App\Repository\ImportedTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recalcule le libellé nettoyé des opérations encore à trier (après une amélioration du nettoyage).
 * Les opérations déjà triées ne sont pas touchées.
 */
#[AsCommand(name: 'app:import:reclean', description: 'Recalcule les libellés nettoyés des opérations importées à trier')]
class RecleanImportLabelsCommand
{
    public function __construct(
        private ImportedTransactionRepository $transactions,
        private LabelCleaner $cleaner,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $changed = 0;
        foreach ($this->transactions->findBy(['status' => 'pending']) as $transaction) {
            $label = $this->cleaner->clean($transaction->getRawLabel());
            if ($label !== $transaction->getLabel()) {
                $transaction->setLabel($label);
                ++$changed;
            }
        }
        $this->em->flush();
        $output->writeln(\sprintf('%d libellé(s) mis à jour.', $changed));

        return Command::SUCCESS;
    }
}
