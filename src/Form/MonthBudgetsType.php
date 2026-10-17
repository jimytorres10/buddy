<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Budgets ajustés d'un mois : un montant par catégorie ; vide = budget habituel.
 * Données : ['c<id>' => centimes|null].
 */
class MonthBudgetsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Category $category */
        foreach ($options['categories'] as $category) {
            $builder->add('c'.$category->getId(), AmountType::class, [
                'label' => $category->getName(),
                'required' => false,
                'constraints' => [new PositiveOrZero(message: 'Le montant doit être positif.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('categories');
    }
}
