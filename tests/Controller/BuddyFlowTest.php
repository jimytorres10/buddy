<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\Expense;
use App\Entity\FixedExpense;
use App\Entity\ImportedTransaction;
use App\Entity\Income;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\FixedExpenseRepository;
use App\Repository\UserRepository;
use App\Service\CategoryDefaults;
use App\Service\MonthSummary;
use App\Tests\Import\StatementParserTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BuddyFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['imported_transaction', 'expense', 'monthly_budget', 'budget', 'fixed_expense', 'income', 'category', '`user`'] as $table) {
            $this->em->getConnection()->executeStatement('TRUNCATE '.$table);
        }
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseRedirects('/login');
    }

    public function testRegisterWizardAndExpenses(): void
    {
        // Inscription
        $this->client->request('GET', '/register');
        $this->client->submitForm('Créer mon compte', [
            'registration_form[firstName]' => 'Camille',
            'registration_form[email]' => 'camille@example.com',
            'registration_form[plainPassword][first]' => 'motdepasse123',
            'registration_form[plainPassword][second]' => 'motdepasse123',
        ]);
        $this->assertResponseRedirects('/onboarding');

        // Le dashboard renvoie au wizard tant qu'il n'est pas terminé
        $this->client->request('GET', '/');
        $this->assertResponseRedirects('/onboarding');

        // Étape 1 : revenus
        $crawler = $this->client->request('GET', '/onboarding/revenus');
        $this->assertSelectorTextContains('h1', 'Tes revenus');
        $form = $crawler->selectButton('Continuer')->form();
        $this->client->submit($form, [
            'recurring_items[items][0][label]' => 'Salaire',
            'recurring_items[items][0][amount]' => '2500',
        ]);
        $this->assertResponseRedirects('/onboarding/depenses-fixes');

        // Étape 2 : dépenses fixes (ligne ajoutée comme le ferait le JS)
        $crawler = $this->client->request('GET', '/onboarding/depenses-fixes');
        $form = $crawler->selectButton('Continuer')->form();
        $values = $form->getPhpValues();
        $values['recurring_items']['items'][0] = ['label' => 'Loyer', 'amount' => '800', 'category' => ''];
        $values['recurring_items']['items'][1] = ['label' => 'Netflix', 'amount' => '13.99', 'category' => ''];
        $this->client->request('POST', '/onboarding/depenses-fixes', $values);
        $this->assertResponseRedirects('/onboarding/budgets');

        // Étape 3 : budgets
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'camille@example.com']);
        $food = static::getContainer()->get(CategoryRepository::class)->findOneBy(['user' => $user, 'name' => 'Alimentation']);
        $crawler = $this->client->request('GET', '/onboarding/budgets');
        $this->client->submit($crawler->selectButton('Continuer')->form(), ['budgets[c'.$food->getId().']' => '400']);
        $this->assertResponseRedirects('/onboarding/recap');

        // Récap : 2500 - 813,99 = 1 686,01 €
        $crawler = $this->client->request('GET', '/onboarding/recap');
        $this->assertStringContainsString('1 686,01 €', str_replace("\u{00A0}", ' ', $crawler->filter('.amount-xl')->text()));
        $this->client->submitForm('Commencer');
        $this->assertResponseRedirects('/');

        $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Bonjour Camille');

        // Ajout d'une dépense
        $crawler = $this->client->request('GET', '/depenses/nouvelle');
        $this->client->submit($crawler->selectButton('Ajouter la dépense')->form(), [
            'expense[amount]' => '45.58',
            'expense[category]' => (string) $food->getId(),
            'expense[date]' => date('Y-m-d'),
            'expense[label]' => 'Courses',
        ]);
        $this->assertResponseRedirects('/depenses?mois='.date('Y-m'));
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.row-label', 'Courses');

        $expense = static::getContainer()->get(ExpenseRepository::class)->findOneBy(['label' => 'Courses']);
        $this->assertSame(4558, $expense->getAmount());

        // Dashboard : budget alimentation 45,58 / 400
        $crawler = $this->client->request('GET', '/');
        $this->assertStringContainsString('45,58', $crawler->filter('.budget-line')->text());

        // Modification
        $crawler = $this->client->request('GET', '/depenses/'.$expense->getId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), ['expense[amount]' => '50']);
        $this->assertResponseRedirects();
        $this->em->clear();
        $this->assertSame(5000, static::getContainer()->get(ExpenseRepository::class)->find($expense->getId())->getAmount());

        // Modification ultérieure des revenus
        $crawler = $this->client->request('GET', '/budget/revenus');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), ['recurring_items[items][0][amount]' => '2600']);
        $this->assertResponseRedirects('/budget');

        // Suppression
        $crawler = $this->client->request('GET', '/depenses/'.$expense->getId().'/modifier');
        $this->client->submit($crawler->filter('dialog form')->form());
        $this->assertResponseRedirects();
        $this->assertNull(static::getContainer()->get(ExpenseRepository::class)->find($expense->getId()));
    }

    public function testCannotEditSomeoneElsesExpense(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');
        $category = static::getContainer()->get(CategoryRepository::class)->findOneBy(['user' => $owner]);

        $expense = (new Expense())->setUser($owner)->setCategory($category)->setAmount(1000);
        $this->em->persist($expense);
        $this->em->flush();

        $this->client->loginUser($other);
        $this->client->request('GET', '/depenses/'.$expense->getId().'/modifier');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testExpenseDetailAndEditInsideList(): void
    {
        $user = $this->createUser('inline@example.com');
        $category = static::getContainer()->get(CategoryRepository::class)->findOneBy(['user' => $user]);
        $expense = (new Expense())->setUser($user)->setCategory($category)->setAmount(1250)->setLabel('Pharmacie');
        $this->em->persist($expense);
        $this->em->flush();
        $this->client->loginUser($user);
        $frame = ['HTTP_TURBO_FRAME' => 'expense_'.$expense->getId()];
        $id = $expense->getId();

        $crawler = $this->client->request('GET', '/depenses');
        $this->assertSelectorExists('turbo-frame#expense_'.$id.' a[href="/depenses/'.$id.'"]');

        // Détail déplié dans le cadre
        $this->client->request('GET', '/depenses/'.$id, server: $frame);
        $this->assertSelectorExists('turbo-frame#expense_'.$id.'.is-open');
        $this->assertSelectorTextContains('.expense-facts', 'Pharmacie');
        $this->assertSelectorExists('.expense-actions a[href="/depenses/'.$id.'/modifier"]');

        // Formulaire dans le cadre : erreur → reste dans le cadre (422)
        $crawler = $this->client->request('GET', '/depenses/'.$id.'/modifier', server: $frame);
        $form = $crawler->filter('.expense-inline-form')->form();
        $this->client->submit($form, ['expense[amount]' => ''], $frame);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('turbo-frame#expense_'.$id.' .field-errors');

        // Succès → la liste est rechargée (turbo-stream refresh)
        $this->client->submit($form, ['expense[amount]' => '20', 'expense[label]' => 'Pharmacie du centre'], $frame);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('action="refresh"', $this->client->getResponse()->getContent());
        $this->em->clear();
        $this->assertSame(2000, static::getContainer()->get(ExpenseRepository::class)->find($id)->getAmount());

        // Replier, puis supprimer depuis le cadre
        $this->client->request('GET', '/depenses/'.$id.'/ligne', server: $frame);
        $this->assertSelectorTextContains('turbo-frame#expense_'.$id, 'Pharmacie du centre');
        $crawler = $this->client->request('GET', '/depenses/'.$id, server: $frame);
        $this->client->submit($crawler->filter('.sheet-actions')->form(), [], $frame);
        $this->assertStringContainsString('action="refresh"', $this->client->getResponse()->getContent());
        $this->assertNull(static::getContainer()->get(ExpenseRepository::class)->find($id));

        // Dépense inconnue
        $this->client->request('GET', '/depenses/999999');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateCustomCategoryWithBudget(): void
    {
        $user = $this->createUser('perso@example.com');
        $this->client->loginUser($user);

        // Nom déjà pris : refusé
        $crawler = $this->client->request('GET', '/budget/budgets');
        $values = $crawler->selectButton('Enregistrer')->form()->getPhpValues();
        $values['budgets']['newCategories'][0] = ['name' => 'alimentation', 'icon' => 'tag', 'amount' => '50'];
        $this->client->request('POST', '/budget/budgets', $values);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field-errors', 'Cette catégorie existe déjà.');

        // Nouvelle catégorie avec budget
        $values['budgets']['newCategories'][0] = ['name' => 'Animaux', 'icon' => 'gift', 'amount' => '60'];
        $this->client->request('POST', '/budget/budgets', $values);
        $this->assertResponseRedirects('/budget');

        $category = static::getContainer()->get(CategoryRepository::class)->findOneBy(['user' => $user, 'name' => 'Animaux']);
        $this->assertNotNull($category);
        $this->assertSame('gift', $category->getIcon());

        $crawler = $this->client->request('GET', '/');
        $this->assertStringContainsString('Animaux', $crawler->filter('.budget-line')->text());
    }

    public function testFixedExpenseLimitedToPeriod(): void
    {
        $user = $this->createUser('periode@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/budget/depenses-fixes');
        $values = $crawler->selectButton('Enregistrer')->form()->getPhpValues();
        $values['recurring_items']['items'] = [
            ['label' => 'Loyer', 'amount' => '800', 'category' => '', 'startMonth' => '', 'endMonth' => ''],
            ['label' => 'Rappel impôts', 'amount' => '150', 'category' => '', 'startMonth' => '2026-12', 'endMonth' => '2026-09'],
        ];

        // Fin avant le début : refusé
        $this->client->request('POST', '/budget/depenses-fixes', $values);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.field-errors', 'Le mois de fin doit être après le mois de début.');

        // Septembre → décembre 2026
        $values['recurring_items']['items'][1]['startMonth'] = '2026-09';
        $values['recurring_items']['items'][1]['endMonth'] = '2026-12';
        $this->client->request('POST', '/budget/depenses-fixes', $values);
        $this->assertResponseRedirects('/budget');

        $summary = static::getContainer()->get(MonthSummary::class);
        $this->assertSame(80000, $summary->compute($user, new \DateTimeImmutable('2026-08-15'))['fixed']);
        $this->assertSame(95000, $summary->compute($user, new \DateTimeImmutable('2026-09-01'))['fixed']);
        $this->assertSame(95000, $summary->compute($user, new \DateTimeImmutable('2026-12-31'))['fixed']);
        $this->assertSame(80000, $summary->compute($user, new \DateTimeImmutable('2027-01-01'))['fixed']);

        // La période est réaffichée à la modification
        $crawler = $this->client->request('GET', '/budget/depenses-fixes');
        $this->assertSame('2026-09', $crawler->filter('input[name="recurring_items[items][1][startMonth]"]')->attr('value'));

        // « Pendant 4 mois » : à partir du début indiqué, ou de ce mois-ci par défaut
        $values['recurring_items']['items'][1] = ['label' => 'Rappel impôts', 'amount' => '150', 'category' => '', 'startMonth' => '2026-10', 'endMonth' => '', 'duration' => '4'];
        $values['recurring_items']['items'][2] = ['label' => 'Salle de sport', 'amount' => '30', 'category' => '', 'startMonth' => '', 'endMonth' => '', 'duration' => '3'];
        $this->client->request('POST', '/budget/depenses-fixes', $values);
        $this->assertResponseRedirects('/budget');

        $this->em->clear();
        $items = static::getContainer()->get(FixedExpenseRepository::class)->findForUser($user);
        $this->assertSame(['2026-10', '2027-01'], [$items[1]->getStartMonth()->format('Y-m'), $items[1]->getEndMonth()->format('Y-m')]);
        $thisMonth = new \DateTimeImmutable('first day of this month');
        $this->assertSame(
            [$thisMonth->format('Y-m'), $thisMonth->modify('+2 months')->format('Y-m')],
            [$items[2]->getStartMonth()->format('Y-m'), $items[2]->getEndMonth()->format('Y-m')],
        );
    }

    public function testStatsPage(): void
    {
        $user = $this->createUser('stats@example.com');
        $categories = static::getContainer()->get(CategoryRepository::class)->findForUser($user);
        [$food, $home] = [$categories[0], $categories[1]];
        $this->em->persist((new Income())->setUser($user)->setLabel('Salaire')->setAmount(200000));
        $this->em->persist((new FixedExpense())->setUser($user)->setLabel('Loyer')->setAmount(70000));
        $this->em->persist((new Budget())->setUser($user)->setCategory($food)->setAmount(30000));
        $this->em->persist((new Budget())->setUser($user)->setCategory($home)->setAmount(10000));
        $this->em->persist((new Expense())->setUser($user)->setCategory($food)->setAmount(35000));
        $this->em->persist((new Expense())->setUser($user)->setCategory($home)->setAmount(5000));
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/graphiques');
        $this->assertResponseIsSuccessful();
        $text = str_replace("\u{00A0}", ' ', $crawler->filter('main')->text());

        // Revenus 2000 : 700 fixes + 400 dépensés, 50 restant au budget logement, 850 d'épargne
        $this->assertStringContainsString('2 000,00 €', $text);
        $legend = str_replace("\u{00A0}", ' ', $crawler->filter('.chart-legend')->first()->text());
        $this->assertStringContainsString('Reste des budgets 50,00 €', $legend);
        $this->assertStringContainsString('Épargne 850,00 €', $legend);
        // Budget alimentation dépassé
        $this->assertStringContainsString('Dépassé de 50,00 €', $text);
        // Donut : 2 catégories, évolution : 6 mois
        $this->assertCount(2, $crawler->filter('.donut-seg'));
        $this->assertCount(6, $crawler->filter('.trend-col'));

        $this->assertSelectorTextContains('.fixed-list', 'Loyer');
        $this->assertSame('0,00 € / 700,00 €', str_replace("\u{00A0}", ' ', $crawler->filter('.area-fixed .section-title span')->last()->text()));

        // Loyer pointé sur une opération bancaire de 690 € → en-tête « prélevé / total »
        $loyer = static::getContainer()->get(FixedExpenseRepository::class)->findOneBy(['user' => $user, 'label' => 'Loyer']);
        $tx = new ImportedTransaction($user, 'h-loyer', new \DateTimeImmutable('first day of this month'), 'PRLV LOYER', 'Loyer', -69000);
        $tx->classify(ImportedTransaction::MATCHED, fixed: $loyer);
        $this->em->persist($tx);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/graphiques');
        $this->assertSame('690,00 € / 700,00 €', str_replace("\u{00A0}", ' ', $crawler->filter('.area-fixed .section-title span')->last()->text()));

        $crawler = $this->client->request('GET', '/graphiques?mois=2020-01');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.month-nav-label strong', 'Janvier 2020');

        // Ajout rapide : sans libellé → erreur, le formulaire reste ouvert
        $form = $crawler->filter('.quick-fixed-form')->form();
        $this->client->submit($form, ['fixed_expense[amount]' => '50']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('details.quick-fixed[open]');

        // Commence au mois affiché, pendant 3 mois
        $this->client->submit($form, ['fixed_expense[label]' => 'Rappel impôts', 'fixed_expense[amount]' => '150', 'fixed_expense[duration]' => '3']);
        $this->assertResponseRedirects('/graphiques?mois=2020-01');
        $fixed = static::getContainer()->get(FixedExpenseRepository::class)->findOneBy(['label' => 'Rappel impôts']);
        $this->assertSame(['2020-01', '2020-03', 15000], [$fixed->getStartMonth()->format('Y-m'), $fixed->getEndMonth()->format('Y-m'), $fixed->getAmount()]);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.fixed-list', 'Rappel impôts');
    }

    public function testAdjustBudgetForOneMonth(): void
    {
        $user = $this->createUser('ajuste@example.com');
        $categories = static::getContainer()->get(CategoryRepository::class)->findForUser($user);
        [$food, $leisure] = [$categories[0], $categories[4]];
        $this->em->persist((new Budget())->setUser($user)->setCategory($food)->setAmount(40000));
        $this->em->persist((new Budget())->setUser($user)->setCategory($leisure)->setAmount(10000));
        $this->em->flush();
        $this->client->loginUser($user);
        $summary = static::getContainer()->get(MonthSummary::class);

        // Décembre : alimentation 550 €, loisirs supprimé (0), cadeaux sans budget habituel → 200 €
        $gifts = $categories[8];
        $crawler = $this->client->request('GET', '/budget/mois/2026-12');
        $this->assertSelectorTextContains('.month-nav-label strong', 'Décembre 2026');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), [
            'month_budgets[c'.$food->getId().']' => '550',
            'month_budgets[c'.$leisure->getId().']' => '0',
            'month_budgets[c'.$gifts->getId().']' => '200',
        ]);
        $this->assertResponseRedirects('/budget/mois/2026-12');

        $budgets = fn (string $date) => array_column(array_map(
            fn ($l) => [$l['category']->getName(), $l['budget']],
            $summary->compute($user, new \DateTimeImmutable($date))['budgets'],
        ), 1, 0);
        $this->assertSame([$food->getName() => 55000, $gifts->getName() => 20000], $budgets('2026-12-10'));
        // Les autres mois gardent les budgets habituels
        $this->assertSame([$food->getName() => 40000, $leisure->getName() => 10000], $budgets('2026-11-10'));

        // Le badge « Ajusté » apparaît, puis retour aux budgets habituels
        $crawler = $this->client->request('GET', '/graphiques?mois=2026-12');
        $this->assertCount(2, $crawler->filter('.bvr .badge-adjusted'));
        $crawler = $this->client->request('GET', '/budget/mois/2026-12');
        $this->client->submit($crawler->selectButton('Budgets habituels')->form());
        $this->assertResponseRedirects('/budget/mois/2026-12');
        $this->assertSame([$food->getName() => 40000, $leisure->getName() => 10000], $budgets('2026-12-10'));
    }

    public function testImportAndTriage(): void
    {
        $user = $this->createUser('import@example.com');
        $this->client->loginUser($user);
        $categories = static::getContainer()->get(CategoryRepository::class)->findForUser($user);
        $byName = array_combine(array_map(fn ($c) => $c->getName(), $categories), $categories);

        // Import du CSV Crédit Agricole
        $csv = sys_get_temp_dir().'/CA'.uniqid().'.csv';
        file_put_contents($csv, mb_convert_encoding(StatementParserTest::CA_CSV, 'Windows-1252', 'UTF-8'));
        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->selectButton('Importer et trier')->form();
        $form['form[file]']->upload($csv);
        $this->client->submit($form);
        $this->assertResponseRedirects('/import/tri');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', '5 opérations importées');

        // Plus ancienne d'abord : salaire (crédit) → revenu mensuel
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.triage-progress', '5 opérations à trier');
        $this->assertSame('Salaire Acme', $crawler->filter('.triage-label')->attr('value'));
        $this->client->submit($crawler->selectButton('Revenu mensuel')->form());
        $this->assertResponseRedirects('/import/tri');

        // Café : catégorie Restaurants suggérée par mot-clé, validée
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSame('Cafe Du Coin', $crawler->filter('.triage-label')->attr('value'));
        $this->assertSelectorTextContains('.is-suggested', 'Restaurants');
        $this->client->submit($crawler->filter('.is-suggested')->form());

        // 2ᵉ café identique : la suggestion vient de l'apprentissage ; on l'ignore puis on annule
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.is-suggested', 'Restaurants');
        $this->client->submit($crawler->selectButton('Ignorer')->form());
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.triage-progress', '2 opérations à trier');
        $this->client->submit($crawler->filter('#undo-form')->form());
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.triage-progress', '3 opérations à trier');
        $this->client->submit($crawler->filter('.is-suggested')->form());

        // Netflix : dépense fixe en Abonnements pendant 12 mois
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSame('Netflix.com', $crawler->filter('.triage-label')->attr('value'));
        $this->client->submit($crawler->selectButton('Enregistrer comme dépense fixe')->form([
            'fixed_category' => (string) $byName['Abonnements']->getId(),
            'months' => '12',
            'fixed_label' => 'Netflix',
        ]));

        // Carrefour : on choisit Alimentation (déjà suggéré par mot-clé)
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.is-suggested', 'Alimentation');
        $this->client->submit($crawler->filter('.triage-categories button')->eq(0)->form());

        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.triage-done', 'Tout est trié');

        // Résultat : 3 dépenses (2 cafés + Carrefour), 1 fixe sur 12 mois, 1 revenu
        $this->em->clear();
        $this->assertSame(3, static::getContainer()->get(ExpenseRepository::class)->count(['user' => $user]));
        $fixed = static::getContainer()->get(FixedExpenseRepository::class)->findForUser($user);
        $this->assertSame(['Netflix', 1399, '2026-10', '2027-09'], [$fixed[0]->getLabel(), $fixed[0]->getAmount(), $fixed[0]->getStartMonth()->format('Y-m'), $fixed[0]->getEndMonth()->format('Y-m')]);
        $this->assertSame('Abonnements', $fixed[0]->getCategory()->getName());
        $this->assertSame(1, static::getContainer()->get(\App\Repository\IncomeRepository::class)->count(['user' => $user]));

        // L'écran Budget montre Netflix comme prélevée ce mois-ci (opération du 15/10)
        if ('2026-10' === date('Y-m')) {
            $this->client->request('GET', '/budget');
            $this->assertSelectorTextContains('.pointed-badge', 'Prélevée le 15/10');
        }

        // Relevé de novembre : le prélèvement Netflix est proposé au pointage (même libellé déjà vu)
        $ofx = sys_get_temp_dir().'/nov'.uniqid().'.ofx';
        file_put_contents($ofx, "<OFX><BANKTRANLIST>\n<STMTTRN><DTPOSTED>20261115<TRNAMT>-13.99<FITID>NOV1<NAME>PRLV SEPA NETFLIX.COM\n</BANKTRANLIST></OFX>");
        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->selectButton('Importer et trier')->form();
        $form['form[file]']->upload($ofx);
        $this->client->submit($form);
        $crawler = $this->client->request('GET', '/import/tri');
        $this->assertSelectorTextContains('.triage-match-btn.is-best', 'Netflix');
        $this->assertSelectorTextContains('.triage-match-btn.is-best', 'déjà pointée pour ce libellé');
        $this->client->submit($crawler->filter('.triage-match-btn.is-best')->form());

        $this->em->clear();
        $this->assertCount(1, static::getContainer()->get(FixedExpenseRepository::class)->findForUser($user));
        $this->assertSame(3, static::getContainer()->get(ExpenseRepository::class)->count(['user' => $user]));
        $november = static::getContainer()->get(\App\Repository\ImportedTransactionRepository::class)
            ->findPointedFixedForMonth($user, new \DateTimeImmutable('2026-11-01'));
        $this->assertSame('matched', reset($november)->getStatus());

        // Annuler un pointage ne supprime pas la dépense fixe
        $crawler = $this->client->request('GET', '/import/tri');
        $this->client->submit($crawler->filter('#undo-form')->form());
        $this->em->clear();
        $this->assertCount(1, static::getContainer()->get(FixedExpenseRepository::class)->findForUser($user));
        unlink($ofx);

        // Ré-import du même fichier : tout est reconnu comme doublon
        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->selectButton('Importer et trier')->form();
        $form['form[file]']->upload($csv);
        $this->client->submit($form);
        $this->assertResponseRedirects('/import');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', '0 opération importée (5 déjà connues, ignorées)');
        unlink($csv);
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('Test')->setPassword('x')->setOnboardingCompleted(true);
        $this->em->persist($user);
        static::getContainer()->get(CategoryDefaults::class)->createFor($user);
        $this->em->flush();

        return $user;
    }
}
