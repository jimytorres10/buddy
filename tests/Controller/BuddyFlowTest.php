<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\Expense;
use App\Entity\FixedExpense;
use App\Entity\Income;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\FixedExpenseRepository;
use App\Repository\UserRepository;
use App\Service\CategoryDefaults;
use App\Service\MonthSummary;
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
        foreach (['expense', 'monthly_budget', 'budget', 'fixed_expense', 'income', 'category', '`user`'] as $table) {
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

        $this->client->request('GET', '/graphiques?mois=2020-01');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.month-nav-label strong', 'Janvier 2020');
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

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('Test')->setPassword('x')->setOnboardingCompleted(true);
        $this->em->persist($user);
        static::getContainer()->get(CategoryDefaults::class)->createFor($user);
        $this->em->flush();

        return $user;
    }
}
