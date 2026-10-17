<?php

namespace App\Import;

/**
 * Lit un export bancaire (OFX/QFX ou CSV) et en extrait les opérations.
 *
 * CSV : séparateur et encodage détectés automatiquement ; la ligne d'en-tête est
 * cherchée dans le fichier (le Crédit Agricole met d'abord des lignes d'infos du compte).
 * Colonnes reconnues : Date, Libellé, Débit / Crédit, ou un seul Montant signé.
 */
class StatementParser
{
    /**
     * @return list<array{date: \DateTimeImmutable, label: string, amount: int, id: ?string}>
     *
     * @throws \InvalidArgumentException si le fichier n'est pas reconnu
     */
    public function parse(string $content, string $filename = ''): array
    {
        $content = $this->toUtf8($content);

        $operations = str_contains(strtoupper(substr($content, 0, 4000)), '<OFX>') || preg_match('/\.(ofx|qfx)$/i', $filename)
            ? $this->parseOfx($content)
            : $this->parseCsv($content);

        if (!$operations) {
            throw new \InvalidArgumentException('Aucune opération trouvée dans ce fichier. Exporte tes opérations au format OFX ou CSV depuis ton espace bancaire.');
        }

        return $operations;
    }

    private function parseOfx(string $content): array
    {
        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|<\/BANKTRANLIST>)/si', $content, $blocks);

        $operations = [];
        foreach ($blocks[1] as $block) {
            $date = $this->ofxTag($block, 'DTPOSTED');
            $amount = $this->ofxTag($block, 'TRNAMT');
            if (null === $date || null === $amount || !preg_match('/^(\d{4})(\d{2})(\d{2})/', $date, $d)) {
                continue;
            }
            $name = (string) $this->ofxTag($block, 'NAME');
            $memo = (string) $this->ofxTag($block, 'MEMO');
            $label = trim($name.(str_contains($name, $memo) ? '' : ' '.$memo));

            $operations[] = [
                'date' => new \DateTimeImmutable("$d[1]-$d[2]-$d[3]"),
                'label' => $label ?: 'Opération',
                'amount' => $this->toCents($amount),
                'id' => $this->ofxTag($block, 'FITID'),
            ];
        }

        return $operations;
    }

    private function ofxTag(string $block, string $tag): ?string
    {
        // SGML (OFX 1.x, balises non fermées) ou XML (OFX 2.x)
        if (!preg_match('/<'.$tag.'>\s*([^<\r\n]*)/i', $block, $m)) {
            return null;
        }
        $value = html_entity_decode(trim($m[1]), \ENT_QUOTES | \ENT_XML1, 'UTF-8');

        return '' === $value ? null : $value;
    }

    private function parseCsv(string $content): array
    {
        $delimiter = $this->detectDelimiter($content);
        $rows = $this->readCsvRows($content, $delimiter);

        $columns = null;
        $operations = [];
        foreach ($rows as $row) {
            if (null === $columns) {
                $columns = $this->detectColumns($row);
                continue;
            }

            $date = $this->parseDate($row[$columns['date']] ?? '');
            if (!$date) {
                continue; // ligne de total, vide ou commentaire
            }

            if (isset($columns['amount'])) {
                $amount = $this->toCents($row[$columns['amount']] ?? '');
            } else {
                $debit = abs($this->toCents($row[$columns['debit']] ?? ''));
                $credit = abs($this->toCents($row[$columns['credit']] ?? ''));
                $amount = $credit - $debit;
            }
            if (0 === $amount) {
                continue;
            }

            $operations[] = [
                'date' => $date,
                'label' => trim(preg_replace('/\s+/u', ' ', (string) ($row[$columns['label']] ?? ''))) ?: 'Opération',
                'amount' => $amount,
                'id' => null,
            ];
        }

        if (null === $columns) {
            throw new \InvalidArgumentException('Format CSV non reconnu : il faut au moins les colonnes « Date », « Libellé » et un montant (« Débit / Crédit » ou « Montant »).');
        }

        return $operations;
    }

    /** @return array{date: int, label: int, amount?: int, debit?: int, credit?: int}|null */
    private function detectColumns(array $row): ?array
    {
        $found = [];
        foreach ($row as $i => $cell) {
            $name = $this->normalize((string) $cell);
            match (true) {
                !isset($found['date']) && str_starts_with($name, 'date') && !str_contains($name, 'valeur') => $found['date'] = $i,
                !isset($found['label']) && (str_contains($name, 'libelle') || str_contains($name, 'label') || 'description' === $name) => $found['label'] = $i,
                !isset($found['debit']) && str_contains($name, 'debit') => $found['debit'] = $i,
                !isset($found['credit']) && str_contains($name, 'credit') => $found['credit'] = $i,
                !isset($found['amount']) && (str_starts_with($name, 'montant') || 'amount' === $name) => $found['amount'] = $i,
                default => null,
            };
        }

        if (!isset($found['date'], $found['label'])) {
            return null;
        }
        if (isset($found['debit'], $found['credit'])) {
            unset($found['amount']);

            return $found;
        }

        return isset($found['amount']) ? $found : null;
    }

    /** @return iterable<list<string>> */
    private function readCsvRows(string $content, string $delimiter): iterable
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            if ([null] !== $row) {
                yield $row;
            }
        }
        fclose($stream);
    }

    private function detectDelimiter(string $content): string
    {
        $sample = substr($content, 0, 5000);
        $counts = [';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t"), ',' => substr_count($sample, ',')];
        // La virgule sert souvent de séparateur décimal : on la pénalise
        $counts[','] = (int) ($counts[','] / 2);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!d/m/Y', '!d/m/y', '!Y-m-d', '!d-m-Y', '!d.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date && $date->format(ltrim($format, '!')) === $value) {
                return $date;
            }
        }

        return null;
    }

    /** « 1 234,56 », « -45.58 », « 45,58 € » → centimes. */
    private function toCents(string $value): int
    {
        $value = preg_replace('/[^\d,.\-+]/', '', str_replace(["\u{00A0}", "\u{202F}", ' '], '', $value));
        if ('' === $value || '-' === $value) {
            return 0;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if (false !== $lastComma && (false === $lastDot || $lastComma > $lastDot)) {
            $value = str_replace(['.', ','], ['', '.'], $value); // virgule décimale
        } else {
            $value = str_replace(',', '', $value);
        }

        return (int) round((float) $value * 100);
    }

    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c']);
    }
}
