<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formatage en français sans dépendre de l'extension PHP intl.
 */
class AppExtension extends AbstractExtension
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('month_label', $this->monthLabel(...)),
            new TwigFilter('month_short', fn (\DateTimeInterface $date) => self::MONTHS_SHORT[(int) $date->format('n') - 1]),
            new TwigFilter('day_label', $this->dayLabel(...)),
            new TwigFilter('short_date', $this->shortDate(...)),
        ];
    }

    /**
     * Centimes → « 1 234,56 € ». $sign : '' (aucun), '-' (préfixe moins), '+-' (signe explicite).
     */
    public function money(?int $cents, string $sign = ''): string
    {
        $cents ??= 0;
        $formatted = number_format(abs($cents) / 100, 2, ',', "\u{00A0}").' €';

        return match (true) {
            '-' === $sign && 0 !== $cents => '- '.$formatted,
            '+-' === $sign && $cents > 0 => '+ '.$formatted,
            '+-' === $sign && $cents < 0, '' === $sign && $cents < 0 => '- '.$formatted,
            default => $formatted,
        };
    }

    public function monthLabel(\DateTimeInterface $date): string
    {
        return ucfirst(self::MONTHS[(int) $date->format('n') - 1]).' '.$date->format('Y');
    }

    public function dayLabel(\DateTimeInterface $date): string
    {
        $day = $date->format('Y-m-d');
        if ($day === date('Y-m-d')) {
            return 'Aujourd\'hui';
        }
        if ($day === date('Y-m-d', strtotime('-1 day'))) {
            return 'Hier';
        }

        return ucfirst(self::DAYS[(int) $date->format('w')]).' '.$date->format('j').' '.self::MONTHS[(int) $date->format('n') - 1];
    }

    public function shortDate(\DateTimeInterface $date): string
    {
        return $date->format('d/m');
    }
}
