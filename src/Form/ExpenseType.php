<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExpenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $options['user'];

        $builder
            ->add('amount', AmountType::class, [
                'label' => 'Montant',
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '0,00', 'step' => '0.01', 'min' => '0', 'class' => 'input-amount', 'autofocus' => true],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                'expanded' => true,
                'query_builder' => fn (CategoryRepository $repo) => $repo->createQueryBuilder('c')
                    ->andWhere('c.user = :user')
                    ->setParameter('user', $user)
                    ->orderBy('c.position'),
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé (optionnel)',
                'required' => false,
                'attr' => ['placeholder' => 'Courses Carrefour'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Expense::class]);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
