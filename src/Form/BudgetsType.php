<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Un montant mensuel par catégorie ; vide = pas de budget.
 * Permet aussi de créer de nouvelles catégories personnalisées.
 * Données : ['c<id>' => centimes|null, 'newCategories' => list<array{name, icon, amount}>].
 */
class BudgetsType extends AbstractType
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

        $builder->add('newCategories', CollectionType::class, [
            'label' => false,
            'entry_type' => NewCategoryBudgetType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'delete_empty' => fn ($row) => null === $row || '' === trim((string) ($row['name'] ?? '')) && !($row['amount'] ?? null),
            'prototype' => true,
        ]);

        // Refuse les noms déjà utilisés (insensible à la casse).
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($options) {
            $taken = array_map(fn (Category $c) => mb_strtolower(trim((string) $c->getName())), $options['categories']);
            foreach ($event->getForm()->get('newCategories') as $row) {
                $name = mb_strtolower(trim((string) $row->get('name')->getData()));
                if ('' === $name) {
                    continue;
                }
                if (\in_array($name, $taken, true)) {
                    $row->get('name')->addError(new FormError('Cette catégorie existe déjà.'));
                }
                $taken[] = $name;
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('categories');
    }
}
