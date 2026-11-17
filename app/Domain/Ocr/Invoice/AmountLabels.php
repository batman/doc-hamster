<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

/**
 * What kind of amount a label names (points 120.2, 235): the gross total – strongest "zu zahlen"/"Amount Due", then
 * Gesamtbetrag/Total, then Summe –, net and subtotal, VAT, a paid amount (it confirms the total), and the ones that are
 * never the total: Skonto, discounts, deposits and carry-overs, change, Pfand, tip, balance.
 */
final class AmountLabels
{
    /** [kind, weight, pattern] in the order they are tried; the first that matches decides. */
    private const KINDS = [
        ['exclude', -100, '/skonto|early payment|cash discount|escompte/u'],
        ['total', 110, '/zahlbetrag|zu zahlende[rn]?(?: betrag)?|zu zahlen|noch offen|fälliger betrag|offener betrag|restbetrag|amount due|total due|balance due|amount payable|total payable|net à payer|montant dû|importe a pagar|totale da pagare|te betalen/u'],
        ['tax', -60, '/\b(?:summe|gesamt|total|totale)\s*(?:der\s*)?(?:mwst|ust|vat|tax|steuer)\b|(?:\bmwst|\bust|\bvat|\btax|steuer)[\s.\-]*(?:gesamt|summe|total|betrag|amount)|(?:enthaltene|darin|davon)\s.*(?:mwst|ust|vat|tax)/u'],
        ['net', -50, '/netto|\bnet\b|subtotal|sub-total|sub total|zwischensumme|warenwert|exkl\.?|excl\.?\s|excluding|ohne mwst|before tax|hors taxe|\bht\b|imponibile|base imponible|subtotaal/u'],
        ['exclude', -40, '/rabatt|nachlass|abzüglich|\babzgl|anzahlung|bereits bezahlt|bereits gezahlt|vorauszahlung|deposit|prepaid|already paid|discount|gutschrift|credit note|guthaben|saldo|übertrag|vortrag|carry[- ]?over|previous balance|opening balance|kontostand|rückgeld|wechselgeld|\bzurück\b|\bchange\b|pfand|leergut|trinkgeld|\btip\b|gratuity|gegeben|tendered|\bbar\b|\bcash\b|versandkosten/u'],
        ['total', 100, '/gesamtbetrag|rechnungsbetrag|endbetrag|endsumme|bruttobetrag|bruttosumme|summe brutto|brutto-?summe|gesamtsumme|grand total|invoice total|total amount|amount total|total ttc|montant total|importe total|totale fattura|totaal|total a pagar|valor total|γενικό σύνολο|γενικο συνολο|σύνολο|συνολο|πληρωτέο|πληρωτεο/u'],
        ['gross', 90, '/(?:betrag|summe|preis|amount|total)\s*(?:\d{1,2}\s*%\s*)?(?:inkl|incl|einschl)|(?:inkl|incl|einschl)\.?\s*(?:\d{1,2}\s*%\s*)?(?:mwst|ust|vat|umsatzsteuer|mehrwertsteuer)\s*(?:betrag|summe|total)|\bbrutto\b|\bgesamt\b|\btotal\b|\bgross\b|\bttc\b|\btotale\b/u'],
        ['tax', -60, '/\bmwst|\bust\b|\bust\.|umsatzsteuer|mehrwertsteuer|\bvat\b|\btax\b|\btva\b|\biva\b|\bbtw\b|sales tax/u'],
        ['gross', 80, '/\bsumme\b|\bsum\b/u'],
        ['paid', 20, '/bezahlt|\bpaid\b|ec-?karte|girocard|kartenzahlung|\bkarte\b|visa|mastercard|maestro|amex|kreditkarte|credit card|debit|apple pay|google pay|paypal/u'],
    ];

    /**
     * @return array{string, int, string|null} kind (total, gross, net, tax, paid, exclude, plain), weight, keyword
     */
    public static function classify(string $text): array
    {
        $t = InvoiceLines::lower($text);
        foreach (self::KINDS as [$kind, $weight, $pattern]) {
            if (preg_match($pattern, $t, $m) === 1) {
                return [$kind, $weight, trim($m[0])];
            }
        }

        return ['plain', 0, null];
    }
}
