<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    public const ICON_LABELS = [
        'tag' => 'Étiquette',
        'cart' => 'Courses',
        'home' => 'Maison',
        'car' => 'Voiture',
        'food' => 'Repas',
        'party' => 'Sorties',
        'health' => 'Santé',
        'bag' => 'Shopping',
        'repeat' => 'Récurrent',
        'gift' => 'Cadeaux',
        'plane' => 'Voyages',
        'book' => 'Études',
        'card' => 'Banque',
        'user' => 'Enfants',
        'dots' => 'Autre',
    ];
    public const ICONS = ['tag', 'cart', 'home', 'car', 'food', 'party', 'health', 'bag', 'repeat', 'gift', 'plane', 'book', 'card', 'user', 'dots'];
    public const COLORS = ['teal', 'green', 'blue', 'orange', 'purple', 'pink', 'red', 'grey'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Indique un nom.')]
    #[Assert\Length(max: 50)]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::ICONS)]
    private string $icon = 'dots';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::COLORS)]
    private string $color = 'teal';

    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
