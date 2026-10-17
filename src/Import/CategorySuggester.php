<?php

namespace App\Import;

use App\Entity\Category;
use App\Entity\ImportedTransaction;
use App\Repository\ImportedTransactionRepository;

/**
 * Propose une catégorie pour une opération importée :
 * 1. celle choisie la dernière fois pour le même libellé (apprentissage) ;
 * 2. sinon un mot-clé connu (enseignes courantes) relié aux catégories par défaut.
 */
class CategorySuggester
{
    /** Nom de catégorie (minuscules) => mots-clés ; l'ordre compte (« UBER EATS » avant « UBER »). */
    private const KEYWORDS = [
        'restaurants' => ['UBER EATS', 'DELIVEROO', 'JUST EAT', 'MCDO', 'MC DONALD', 'MCDONALD', 'BURGER KING', 'KFC', 'QUICK', 'RESTAURANT', 'BRASSERIE', 'PIZZ', 'SUSHI', 'KEBAB', 'STARBUCKS', 'CAFE '],
        'alimentation' => ['CARREFOUR', 'LECLERC', 'AUCHAN', 'LIDL', 'ALDI', 'INTERMARCHE', 'MONOPRIX', 'FRANPRIX', 'CASINO', 'SUPER U', 'HYPER U', 'SYSTEME U', 'PICARD', 'BIOCOOP', 'GRAND FRAIS', 'NETTO', 'LEADER PRICE', 'BOULANGERIE', 'BOUCHERIE', 'PRIMEUR'],
        'abonnements' => ['NETFLIX', 'SPOTIFY', 'DEEZER', 'DISNEY', 'CANAL', 'AMAZON PRIME', 'APPLE.COM', 'GOOGLE', 'MICROSOFT', 'FREE MOBILE', 'FREE TELECOM', 'ORANGE', 'SFR', 'BOUYGUES', 'SOSH', 'RED BY', 'OVH', 'ADOBE', 'BASIC FIT', 'FITNESS'],
        'transport' => ['SNCF', 'RATP', 'NAVIGO', 'TOTAL', 'ESSO', 'SHELL', 'AVIA', 'BP ', 'UBER', 'BOLT', 'BLABLACAR', 'PEAGE', 'VINCI', 'APRR', 'SANEF', 'PARKING', 'INDIGO', 'STATION'],
        'santé' => ['PHARMACIE', 'PHARMA', 'DOCTOLIB', 'MEDECIN', 'DOCTEUR', 'DENTISTE', 'LABO', 'OPTIC', 'KINE', 'HOPITAL', 'CLINIQUE'],
        'logement' => ['EDF', 'ENGIE', 'LOYER', 'VEOLIA', 'SUEZ', 'EAU DE', 'SYNDIC', 'FONCIA', 'NEXITY'],
        'shopping' => ['AMAZON', 'ZARA', 'H&M', 'H & M', 'KIABI', 'IKEA', 'CDISCOUNT', 'ACTION', 'PRIMARK', 'DECATHLON', 'LEROY MERLIN', 'CASTORAMA', 'BOULANGER', 'DARTY', 'SEPHORA', 'ZALANDO', 'SHEIN', 'VINTED'],
        'loisirs' => ['CINEMA', 'PATHE', 'UGC', 'FNAC', 'CULTURA', 'STEAM', 'PLAYSTATION', 'NINTENDO', 'TICKETMASTER', 'BOWLING', 'MUSEE'],
    ];

    public function __construct(private ImportedTransactionRepository $transactions)
    {
    }

    /** @param list<Category> $categories */
    public function suggest(ImportedTransaction $transaction, array $categories): ?Category
    {
        if ($transaction->isCredit()) {
            return null;
        }

        $learned = $this->transactions->findLearnedCategory($transaction->getUser(), $transaction->getLabel());
        if ($learned) {
            return $learned;
        }

        $byName = [];
        foreach ($categories as $category) {
            $byName[mb_strtolower($category->getName())] = $category;
        }

        $haystack = ' '.mb_strtoupper($transaction->getRawLabel()).' ';
        foreach (self::KEYWORDS as $name => $keywords) {
            if (!isset($byName[$name])) {
                continue;
            }
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $byName[$name];
                }
            }
        }

        return null;
    }
}
