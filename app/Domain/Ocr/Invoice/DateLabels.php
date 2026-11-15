<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

/**
 * What a date on an invoice is, by its label (point 234): the issue date (Rechnungsdatum, Invoice Date, Date de
 * facture …; a bare "Datum"/"Date" is the generic one), delivery or service, order, due, Skonto, paid – or a date
 * that is never the invoice date (billing period, printed on, valid until, customer since, birth date, tax year).
 * German, English, French, Spanish, Italian, Dutch and Portuguese.
 */
final class DateLabels
{
    public const ISSUE = 'issue';

    public const GENERIC = 'generic';

    public const DELIVERY = 'delivery';

    public const ORDER = 'order';

    public const DUE = 'due';

    public const SKONTO = 'skonto';

    public const PAID = 'paid';

    public const NEVER = 'never';

    private const LABELS = [
        self::ISSUE => 'rechnungsdatum|rechnungs-datum|datum der rechnung|rechnung vom|belegdatum|ausstellungsdatum|ausgestellt am|kaufdatum|verkaufsdatum|quittungsdatum|invoice date|date of invoice|invoice dated|date of issue|issue date|date issued|issued on|issued|billed on|bill date|billing date|dated|date de (?:la )?facture|date facture|date d\'émission|date d\'emission|fecha de emisi[oó]n|fecha (?:de (?:la )?)?factura|data (?:della )?fattura|data di emissione|factuurdatum|data de emiss[aã]o|data da fatura',
        self::DELIVERY => 'datum der lieferung|datum der leistung|lieferdatum|liefertermin|lieferung vom|geliefert am|leistungsdatum|leistungszeitraum|leistungszeit|leistung vom|ausführungsdatum|delivery date|date of delivery|delivered on|ship(?:ping)? date|shipped on|date shipped|service period|service date|date of service|period of service|date de livraison|fecha de entrega|data di consegna|leverdatum|leveringsdatum|data de entrega',
        self::ORDER => 'bestelldatum|bestellung vom|bestellt am|auftragsdatum|auftrag vom|order date|ordered on|date of order|date de commande|fecha (?:del? )?pedido|data (?:dell\')?ordine|besteldatum|data do pedido',
        self::DUE => 'fälligkeitsdatum|fälligkeit|fällig am|fällig bis|fällig zum|fällig|zahlbar bis|zahlbar zum|zahlbar spätestens(?: am| bis)?|zahlungsziel|zahlungsfrist|due date|due on|due by|payment due|date due|payable by|pay by|date d\'échéance|échéance|fecha de vencimiento|vencimiento|data di scadenza|scadenza|vervaldatum|uiterste betaaldatum|data de vencimento',
        self::SKONTO => 'skonto|early payment|cash discount|escompte',
        self::PAID => 'bezahlt am|bezahlt|zahlungsdatum|zahlungseingang|paid on|paid|payment date|date paid|date of payment|payé le|pagado el|fecha de pago|pagato il|betaald op',
        self::NEVER => 'abrechnungszeitraum|abrechnungsperiode|billing period|statement period|gedruckt am|druckdatum|printed on|print date|gültig bis|gültig ab|valid until|valid through|valid till|expiry date|expires|ablaufdatum|kunde seit|kundin seit|customer since|member since|mitglied seit|geburtsdatum|geboren am|date of birth|birth date|steuerjahr|tax year|veranlagungsjahr|wirtschaftsjahr|stand',
        self::GENERIC => 'datum|date|fecha|data',
    ];

    /**
     * The date labels on a line, left to right: kind and byte offsets; where two overlap the longer one wins
     * ("Due Date" over "Date").
     *
     * @return list<array{kind: string, s: int, e: int}>
     */
    public static function find(string $text): array
    {
        $found = [];
        foreach (self::LABELS as $kind => $words) {
            if (preg_match_all('/(?<!\p{L})(?:'.$words.')(?!\p{L})/iu', $text, $m, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($m[0] as [$word, $s]) {
                $found[] = ['kind' => $kind, 's' => $s, 'e' => $s + strlen($word)];
            }
        }
        usort($found, static fn (array $a, array $b): int => [$b['e'] - $b['s'], $a['s']] <=> [$a['e'] - $a['s'], $b['s']]);
        $kept = [];
        foreach ($found as $f) {
            foreach ($kept as $k) {
                if ($f['s'] < $k['e'] && $f['e'] > $k['s']) {
                    continue 2;
                }
            }
            $kept[] = $f;
        }
        usort($kept, static fn (array $a, array $b): int => $a['s'] <=> $b['s']);

        return $kept;
    }

    /**
     * The label of the date at $start..$end: the nearest one in front of it that no other date took, else the one
     * right behind it ("06.02.2026 (Lieferdatum)", "bis 10.11.2026 … Skonto") – marked as behind.
     *
     * @param  list<array{kind: string, s: int, e: int}>  $labels
     * @param  list<int>  $taken  start offsets of labels an earlier date on the line used
     * @param  int  $next  where the next date on the line starts – a label behind that is the next date's
     * @return array{kind: string, s: int, e: int, behind: bool}|null
     */
    public static function at(array $labels, int $start, int $end, array $taken, int $next = PHP_INT_MAX): ?array
    {
        $before = null;
        foreach ($labels as $label) {
            if ($label['e'] <= $start) {
                $before = $label;
            }
        }
        if ($before !== null && ! in_array($before['s'], $taken, true)) {
            return [...$before, 'behind' => false];
        }
        foreach ($labels as $label) {
            // A kind that rules a date out counts anywhere behind it; the others only right behind ("(Lieferdatum)").
            $ruling = in_array($label['kind'], [self::SKONTO, self::NEVER, self::PAID, self::DUE], true);
            if ($label['s'] >= $end && $label['e'] <= $next && ($ruling || $label['s'] - $end <= 3)) {
                return [...$label, 'behind' => true];
            }
        }

        return null;
    }
}
