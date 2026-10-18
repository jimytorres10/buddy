<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Champs de période « De / À » ou « pendant N mois », partagés par les dépenses fixes,
 * les revenus et les changements de revenu (entités utilisant MonthPeriodTrait).
 */
final class PeriodFields
{
    public static function add(FormBuilderInterface $builder, bool $startRequired = false): void
    {
        $builder
            ->add('startMonth', MonthType::class, ['label' => 'De', 'required' => $startRequired])
            ->add('endMonth', MonthType::class, ['label' => 'À', 'required' => false])
            ->add('duration', IntegerType::class, [
                'label' => 'Pendant',
                'mapped' => false,
                'required' => false,
                'attr' => ['min' => 1, 'max' => 120, 'placeholder' => '4'],
                'constraints' => [new Range(min: 1, max: 120, notInRangeMessage: 'Entre {{ min }} et {{ max }} mois.')],
            ])
            // « Pendant N mois » : la fin est calculée depuis le début (ce mois-ci par défaut)
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $months = $event->getForm()->get('duration')->getData();
                $item = $event->getData();
                if (!\is_object($item) || !method_exists($item, 'setEndMonth') || !\is_int($months) || $months < 1 || $months > 120) {
                    return;
                }
                $start = $item->getStartMonth() ?? new \DateTimeImmutable('first day of this month midnight');
                $item->setStartMonth($start)->setEndMonth($start->modify(\sprintf('+%d months', $months - 1)));
            });
    }
}
