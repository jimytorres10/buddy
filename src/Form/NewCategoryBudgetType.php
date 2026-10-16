<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Création rapide d'une catégorie personnalisée depuis l'écran des budgets.
 * Données : ['name' => string, 'icon' => string, 'amount' => centimes|null].
 */
class NewCategoryBudgetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Nom de la catégorie', 'aria-label' => 'Nom de la catégorie', 'maxlength' => 50],
                'constraints' => [
                    new NotBlank(message: 'Indique un nom.'),
                    new Length(max: 50),
                ],
            ])
            ->add('icon', ChoiceType::class, [
                'label' => false,
                'choices' => array_flip(Category::ICON_LABELS),
                'data' => 'tag',
                'attr' => ['aria-label' => 'Icône'],
            ])
            ->add('amount', AmountType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'Budget', 'step' => '0.01', 'min' => '0', 'aria-label' => 'Budget mensuel'],
                'constraints' => [new PositiveOrZero(message: 'Le montant doit être positif.')],
            ]);
    }
}
