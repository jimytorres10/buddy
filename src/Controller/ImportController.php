<?php

namespace App\Controller;

use App\Entity\ImportedTransaction;
use App\Entity\User;
use App\Import\CategorySuggester;
use App\Import\TransactionImporter;
use App\Repository\CategoryRepository;
use App\Repository\ImportedTransactionRepository;
use App\Security\OwnerVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Route('/import')]
class ImportController extends AbstractController
{
    #[Route('', name: 'app_import')]
    public function upload(Request $request, #[CurrentUser] User $user, TransactionImporter $importer, ImportedTransactionRepository $transactions): Response
    {
        $form = $this->createFormBuilder()
            ->add('file', FileType::class, [
                'label' => 'Fichier exporté de ta banque',
                'constraints' => [
                    new NotNull(message: 'Choisis un fichier.'),
                    new File(maxSize: '5M'),
                    // Contrôle sur le nom (la détection MIME exige l'extension PHP fileinfo) ; le contenu est vérifié à la lecture
                    new Callback(function (?UploadedFile $file, ExecutionContextInterface $context): void {
                        if ($file && !preg_match('/\.(ofx|qfx|csv|txt)$/i', $file->getClientOriginalName())) {
                            $context->addViolation('Formats acceptés : OFX ou CSV.');
                        }
                    }),
                ],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            try {
                $result = $importer->import($user, (string) file_get_contents($file->getPathname()), $file->getClientOriginalName());
                $this->addFlash('success', \sprintf(
                    '%d opération%s importée%s%s.',
                    $result['added'], $result['added'] > 1 ? 's' : '', $result['added'] > 1 ? 's' : '',
                    $result['duplicates'] ? \sprintf(' (%d déjà connue%s, ignorée%s)', $result['duplicates'], $result['duplicates'] > 1 ? 's' : '', $result['duplicates'] > 1 ? 's' : '') : '',
                ));

                return $this->redirectToRoute($result['added'] ? 'app_import_triage' : 'app_import');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('import/upload.html.twig', [
            'form' => $form,
            'counts' => $transactions->countByStatus($user),
        ]);
    }

    #[Route('/tri', name: 'app_import_triage')]
    public function triage(
        #[CurrentUser] User $user,
        ImportedTransactionRepository $transactions,
        CategoryRepository $categories,
        CategorySuggester $suggester,
    ): Response {
        $transaction = $transactions->findNextPending($user);
        $categoryList = $categories->findForUser($user);

        return $this->render('import/triage.html.twig', [
            'transaction' => $transaction,
            'categories' => $categoryList,
            'suggestion' => $transaction ? $suggester->suggest($transaction, $categoryList) : null,
            'remaining' => $transactions->countPending($user),
            'canUndo' => null !== $transactions->findLastClassified($user),
        ]);
    }

    #[Route('/{id}/classer', name: 'app_import_classify', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(OwnerVoter::MANAGE, 'transaction')]
    public function classify(ImportedTransaction $transaction, Request $request, #[CurrentUser] User $user, TransactionImporter $importer, CategoryRepository $categories): Response
    {
        $payload = $request->getPayload();
        if (!$transaction->isPending() || !$this->isCsrfTokenValid('classify'.$transaction->getId(), $payload->getString('_token'))) {
            return $this->redirectToRoute('app_import_triage', [], Response::HTTP_SEE_OTHER);
        }

        $label = trim($payload->getString('label')) ?: $transaction->getLabel();
        // Champs souvent vides : getInt() refuserait « », on convertit nous-mêmes
        $int = fn (string $key): int => (int) $payload->getString($key);
        $months = $int('months');
        // Un bouton de catégorie envoie seulement « category » : c'est une dépense ponctuelle.
        // Le formulaire « dépense fixe » a son propre champ (fixed_category) pour ne pas se mélanger.
        $decision = $payload->getString('decision') ?: ($int('category') ? 'expense' : '');
        $categoryId = 'fixed' === $decision ? $int('fixed_category') : $int('category');
        $category = $categoryId ? $categories->findOneBy(['id' => $categoryId, 'user' => $user]) : null;

        match ($decision) {
            'expense' => $category && !$transaction->isCredit()
                ? $importer->toExpense($transaction, $category, $label)
                : $this->addFlash('error', 'Choisis une catégorie pour cette dépense.'),
            'fixed' => $transaction->isCredit()
                ? $this->addFlash('error', 'Une rentrée d\'argent ne peut pas être une dépense fixe.')
                : $importer->toFixedExpense($transaction, $category, $label, $months >= 1 && $months <= 120 ? $months : null),
            'income' => $transaction->isCredit()
                ? $importer->toIncome($transaction, $label)
                : $this->addFlash('error', 'Un débit ne peut pas être un revenu.'),
            'ignore' => $importer->ignore($transaction),
            default => null,
        };

        return $this->redirectToRoute('app_import_triage', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/annuler', name: 'app_import_undo', methods: ['POST'])]
    public function undo(Request $request, #[CurrentUser] User $user, ImportedTransactionRepository $transactions, TransactionImporter $importer): Response
    {
        $last = $transactions->findLastClassified($user);
        if ($last && $this->isCsrfTokenValid('import_undo', $request->getPayload()->getString('_token'))) {
            $importer->undo($last);
        }

        return $this->redirectToRoute('app_import_triage', [], Response::HTTP_SEE_OTHER);
    }
}
