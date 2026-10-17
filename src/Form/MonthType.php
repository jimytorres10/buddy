<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Champ « mois » (<input type="month">, valeur AAAA-MM) ↔ DateTimeImmutable au 1er du mois.
 */
class MonthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            fn (?\DateTimeInterface $month) => $month?->format('Y-m'),
            function (?string $value): ?\DateTimeImmutable {
                if (null === $value || '' === trim($value)) {
                    return null;
                }
                $month = \DateTimeImmutable::createFromFormat('!Y-m', trim($value));
                if (!$month || $month->format('Y-m') !== trim($value)) {
                    throw new TransformationFailedException('Mois invalide.');
                }

                return $month;
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'month';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'invalid_message' => 'Choisis un mois valide (AAAA-MM).',
            'attr' => ['placeholder' => 'AAAA-MM', 'pattern' => '\d{4}-\d{2}'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'month';
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
