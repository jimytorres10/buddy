<?php

namespace App\Tests\Import;

use App\Import\LabelCleaner;
use App\Import\StatementParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatementParserTest extends TestCase
{
    /** Export CSV au format Crédit Agricole (Windows-1252, lignes d'infos avant l'en-tête). */
    public const CA_CSV = <<<'CSV'
        Téléchargement du 17/10/2026;
        ;
        CCHQ;Compte de Dépôt carte;n° 12345678901;
        Solde au 16/10/2026 1 234,56 €
        ;
        Liste des opérations du compte courant du 01/10/2026 au 17/10/2026;
        ;
        Date;Libellé;Débit euros;Crédit euros;
        16/10/2026;"PAIEMENT PAR CARTE X1234 CARREFOUR MARKET 15/10   ";45,58;;
        15/10/2026;"PRLV SEPA NETFLIX.COM
        REF123456789";13,99;;
        14/10/2026;"VIREMENT EN VOTRE FAVEUR SALAIRE ACME";;2 400,00;
        14/10/2026;"PAIEMENT PAR CARTE X1234 CAFE DU COIN 14/10";3,50;;
        14/10/2026;"PAIEMENT PAR CARTE X1234 CAFE DU COIN 14/10";3,50;;
        CSV;

    public const OFX = <<<'OFX'
        OFXHEADER:100
        DATA:OFXSGML
        VERSION:102

        <OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>
        <STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261012<TRNAMT>-62.30<FITID>A1B2C3<NAME>CB LECLERC 11/10<MEMO>CB LECLERC 11/10
        <STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261005<TRNAMT>150.00<FITID>D4E5F6<NAME>VIR CAF
        </BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
        OFX;

    public function testCreditAgricoleCsv(): void
    {
        $ops = (new StatementParser())->parse(mb_convert_encoding(self::CA_CSV, 'Windows-1252', 'UTF-8'), 'CA20261017.csv');

        $this->assertCount(5, $ops);
        $this->assertSame('2026-10-16', $ops[0]['date']->format('Y-m-d'));
        $this->assertSame(-4558, $ops[0]['amount']);
        $this->assertSame('PRLV SEPA NETFLIX.COM REF123456789', $ops[1]['label']);
        $this->assertSame(240000, $ops[2]['amount']);
        $this->assertSame(-350, $ops[4]['amount']);
    }

    public function testOfx(): void
    {
        $ops = (new StatementParser())->parse(self::OFX, 'releve.ofx');

        $this->assertCount(2, $ops);
        $this->assertSame([-6230, 'A1B2C3', 'CB LECLERC 11/10'], [$ops[0]['amount'], $ops[0]['id'], $ops[0]['label']]);
        $this->assertSame([15000, '2026-10-05'], [$ops[1]['amount'], $ops[1]['date']->format('Y-m-d')]);
    }

    public function testUnknownFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StatementParser())->parse("nom;prenom\nDupont;Jean", 'contacts.csv');
    }

    public function testSignedAmountCsv(): void
    {
        $ops = (new StatementParser())->parse("Date,Description,Amount\n2026-10-01,SPOTIFY,-10.99\n2026-10-02,REFUND,5.00", 'export.csv');

        $this->assertSame([-1099, 500], array_column($ops, 'amount'));
    }

    #[DataProvider('labels')]
    public function testLabelCleaner(string $raw, string $expected): void
    {
        $this->assertSame($expected, (new LabelCleaner())->clean($raw));
    }

    public static function labels(): iterable
    {
        yield ['PAIEMENT PAR CARTE X1234 CARREFOUR MARKET 15/10', 'Carrefour Market'];
        yield ['PRLV SEPA NETFLIX.COM REF123456789', 'Netflix.com'];
        yield ['CB LECLERC 11/10', 'Leclerc'];
        yield ['VIREMENT EN VOTRE FAVEUR SALAIRE ACME', 'Salaire Acme'];
        yield ['CB BOULANGERIE DE LA GARE 02/10/26', 'Boulangerie De La Gare'];
        yield ['RETRAIT AU DISTRIBUTEUR 12/10', 'Retrait Au Distributeur'];
        // Format Crédit Agricole : l'enseigne après le tiret pour les cartes, référence ignorée sinon
        yield ['Paiement par carte X0123 SAINT JEAN 01/10 - Intermarché', 'Intermarche'];
        yield ['Prélèvement FREE MOBILE - FM123456 Free Mobile mandat ABC', 'Free Mobile'];
        yield ['Virement émis vers M DUPONT LOYER', 'M Dupont Loyer'];
    }
}
