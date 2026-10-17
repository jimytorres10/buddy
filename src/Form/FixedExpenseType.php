<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\FixedExpense;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

class FixedExpenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $options['user'];

        $builder
            ->add('label', TextType::class, ['label' => 'Libellé', 'attr' => ['placeholder' => 'Loyer']])
            ->add('amount', AmountType::class, ['label' => 'Montant / mois'])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                'required' => false,
                'placeholder' => 'Aucune',
                'query_builder' => fn (CategoryRepository $repo) => $repo->createQueryBuilder('c')
                    ->andWhere('c.user = :user')
                    ->setParameter('user', $user)
                    ->orderBy('c.position'),
            ])
            ->add('startMonth', MonthType::class, ['label' => 'De'])
            ->add('endMonth', MonthType::class, ['label' => 'À'])
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
                $fixed = $event->getData();
                if (!$fixed instanceof FixedExpense || !\is_int($months) || $months < 1 || $months > 120) {
                    return;
                }
                $start = $fixed->getStartMonth() ?? new \DateTimeImmutable('first day of this month midnight');
                $fixed->setStartMonth($start)->setEndMonth($start->modify(\sprintf('+%d months', $months - 1)));
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FixedExpense::class]);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
