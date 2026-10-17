<?php

namespace App\Import;

/**
 * Rend lisibles les libellés bancaires :
 * « PAIEMENT PAR CARTE X1234 CARREFOUR MARKET 11/10 » → « Carrefour Market ».
 */
class LabelCleaner
{
    private const PREFIXES = [
        'PAIEMENT PAR CARTE', 'PAIEMENT CB', 'ACHAT CB', 'CARTE', 'CB',
        'PRELEVEMENT SEPA', 'PRELEVEMENT', 'PRLV SEPA', 'PRLV',
        'VIREMENT EN VOTRE FAVEUR', 'VIREMENT SEPA RECU', 'VIREMENT SEPA EMIS', 'VIREMENT SEPA', 'VIREMENT', 'VIR SEPA RECU', 'VIR SEPA', 'VIR INST', 'VIR',
        'RETRAIT AU DISTRIBUTEUR', 'RETRAIT DAB', 'RETRAIT',
        'AVOIR CARTE', 'REMBOURSEMENT CB', 'ECHEANCE PRET', 'COTISATION',
    ];

    public function clean(string $raw): string
    {
        $label = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $raw)));
        $label = strtr($label, ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ç' => 'C', 'Ô' => 'O', 'Î' => 'I', 'Û' => 'U']);

        // Dates (11/10, 11/10/26), références de carte, longues suites de chiffres, références de fin
        $label = preg_replace('/\b\d{2}\/\d{2}(\/\d{2,4})?\b/', ' ', $label);
        $label = preg_replace('/\b(X|CARTE ?)\d{4}\b/', ' ', $label);
        $label = preg_replace('/\b\d{5,}\b/', ' ', $label);
        $label = preg_replace('/\b(REF|MANDAT|ID|NUM|FACT)\s*:?.*$/', '', $label);
        $label = trim(preg_replace('/\s+/', ' ', $label), " -*.:/");
        $withoutRefs = $label;

        // Préfixes de type d'opération (répétés : « CB CARTE … »), sauf si le libellé n'est que ça
        while (!\in_array($label, self::PREFIXES, true)) {
            $before = $label;
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($label, $prefix.' ')) {
                    $label = trim(substr($label, \strlen($prefix)));
                    break;
                }
            }
            if ($label === $before) {
                break;
            }
        }

        if ('' === $label) {
            $label = $withoutRefs ?: mb_strtoupper(trim($raw));
        }

        return mb_convert_case(mb_strtolower($label), \MB_CASE_TITLE);
    }
}
