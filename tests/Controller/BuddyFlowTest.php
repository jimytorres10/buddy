<?php

namespace App\Tests\Controller;

use App\Entity\Expense;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\UserRepository;
use App\Service\CategoryDefaults;
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
        foreach (['expense', 'budget', 'fixed_expense', 'income', 'category', '`user`'] as $table) {
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

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('Test')->setPassword('x')->setOnboardingCompleted(true);
        $this->em->persist($user);
        static::getContainer()->get(CategoryDefaults::class)->createFor($user);
        $this->em->flush();

        return $user;
    }
}
