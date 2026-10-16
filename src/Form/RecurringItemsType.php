<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * Liste éditable de lignes récurrentes (revenus ou dépenses fixes).
 */
class RecurringItemsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('items', CollectionType::class, [
            'label' => false,
            'entry_type' => $options['entry_type'],
            'entry_options' => ['label' => false] + $options['entry_options'],
            'allow_add' => true,
            'allow_delete' => true,
            'delete_empty' => fn ($item) => null === $item || (!$item->getLabel() && !$item->getAmount()),
            'prototype' => true,
            'constraints' => [new Valid()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('entry_type');
        $resolver->setDefaults(['entry_options' => []]);
    }
}
