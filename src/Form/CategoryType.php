<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom', 'attr' => ['placeholder' => 'Voyages']])
            ->add('icon', ChoiceType::class, [
                'label' => 'Icône',
                'expanded' => true,
                'choices' => array_combine(Category::ICONS, Category::ICONS),
            ])
            ->add('color', ChoiceType::class, [
                'label' => 'Couleur',
                'expanded' => true,
                'choices' => array_combine(Category::COLORS, Category::COLORS),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Category::class]);
    }
}
