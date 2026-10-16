<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Montant en euros saisi à l'écran, stocké en centimes (int).
 */
class AmountType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'currency' => false,
            'divisor' => 100,
            'input' => 'integer',
            'scale' => 2,
            'html5' => true,
            'attr' => ['inputmode' => 'decimal', 'placeholder' => '0,00', 'step' => '0.01', 'min' => '0'],
            'invalid_message' => 'Montant invalide.',
        ]);
    }

    public function getParent(): string
    {
        return MoneyType::class;
    }
}
