<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée le jeu de catégories proposé à chaque nouvel utilisateur.
 */
class CategoryDefaults
{
    private const DEFAULTS = [
        ['Alimentation', 'cart', 'green'],
        ['Logement', 'home', 'teal'],
        ['Transport', 'car', 'blue'],
        ['Restaurants', 'food', 'orange'],
        ['Loisirs', 'party', 'purple'],
        ['Santé', 'health', 'red'],
        ['Shopping', 'bag', 'pink'],
        ['Abonnements', 'repeat', 'teal'],
        ['Autre', 'dots', 'grey'],
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function createFor(User $user): void
    {
        foreach (self::DEFAULTS as $position => [$name, $icon, $color]) {
            $this->em->persist((new Category())
                ->setUser($user)
                ->setName($name)
                ->setIcon($icon)
                ->setColor($color)
                ->setPosition($position));
        }
    }
}
