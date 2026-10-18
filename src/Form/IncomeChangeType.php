<?php

namespace App\Form;

use App\Entity\IncomeChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Nouveau montant d'un revenu sur une période : le début est obligatoire, la fin facultative. */
class IncomeChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('amount', AmountType::class, ['label' => 'Nouveau montant / mois']);
        PeriodFields::add($builder, startRequired: true);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IncomeChange::class]);
    }
}
