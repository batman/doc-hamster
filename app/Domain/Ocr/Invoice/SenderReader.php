<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;

/**
 * The sender and payee (A248, point 133): next to Kontoinhaber / Empfänger in the bank block, else the sender line
 * above the address ("Muster GmbH · Hauptstr. 1 · 12345 Musterstadt"), else the company name of the letterhead – a
 * legal form (GmbH, Ltd, LLC …) and large print near the top win – else the first name-like line at the top. Label and
 * term lines ("Terms : Due on Receipt", "Rechnungsdatum: …", "Bill to", "Tel", "IBAN" …) are never a sender.
 *
 * @phpstan-import-type Line from InvoiceLines
 */
final class SenderReader
{
    /** Legal forms that mark a company name (case matters: "AG" yes, "ag" no). */
    private const LEGAL = '(?:GmbH(?:\s*&\s*Co\.?\s*KG)?|gGmbH|mbH|AG|UG(?:\s*\(haftungsbeschränkt\))?|KG|OHG|GbR|KGaA|SE|e\.\s?K\.|e\.\s?Kfm\.|e\.\s?V\.|Ltd\.?|LTD\.?|Limited|LIMITED|LLC|L\.L\.C\.|LLP|PLC|plc|Inc\.?|INC\.?|Corp\.?|Co\.|S\.A\.?|SA|S\.A\.S\.|SAS|SARL|S\.à\s?r\.l\.|SRL|S\.r\.l\.|S\.p\.A\.|B\.V\.|BV|N\.V\.|NV|Pty\.?|AB|ApS|A\/S|AS|Oy|sp\.\s?z\s?o\.o\.|s\.r\.o\.|GesmbH)';

    /** Words that make a line a label or term, not a name (German and English). */
    private const LABEL = '/(?<!\p{L})(?:terms?|due|payment|zahlungs\p{L}*|zahlbar|f[äa]llig\p{L}*|invoice\p{L}*|rechnung\p{L}*|date|datum|bill(?:ed)?\s+to|ship(?:ped)?\s+to|sold\s+to|deliver\p{L}*|liefer\p{L}*|rechnungsadresse|kunde\p{L}*|customer|client|page|seite|tel\.?|telefon|phone|fax|mobil\p{L}*|e-?mail|www|https?|ust\p{L}*|vat|tax|steuer\p{L}*|iban|bic|swift|bank\p{L}*|konto\p{L}*|account|amount|betrag|total|summe|order|bestell\p{L}*|auftrag\p{L}*|nr\.?|no\.|number|nummer|reference|referenz|betreff|subject|description|beschreibung|qty|menge|preis|price|balance|receipt|quittung|beleg)(?!\p{L})/iu';

    /**
     * @param  list<Line>  $lines
     */
    public static function read(array $lines): ?InvoiceProposal
    {
        return self::bankBlock($lines) ?? self::senderLine($lines) ?? self::letterhead($lines);
    }

    /** Point 133: a line that labels or states terms, or carries contact details – never a sender. */
    public static function isLabel(string $text): bool
    {
        return preg_match(self::LABEL, $text) === 1
            // "Label : value" / "Label: value"
            || preg_match('/\p{L}\s*:\s*\S/u', $text) === 1
            || preg_match('/@|\bwww\.|\.(?:com|de|net|org|eu)\b/iu', $text) === 1;
    }

    /**
     * @param  list<Line>  $lines
     */
    private static function bankBlock(array $lines): ?InvoiceProposal
    {
        $ibanLines = [];
        foreach ($lines as $line) {
            if (preg_match('/\biban\b/iu', $line['text']) === 1) {
                $ibanLines[] = $line['i'];
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/(?<!\p{L})(kontoinhaber(?:in)?|zahlungsempfänger|empfänger|account holder|inhaber|beneficiary)\s*(:)?\s*(.+)$/iu', $line['text'], $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $near = array_filter($ibanLines, static fn (int $i): bool => abs($i - $line['i']) <= 3) !== [];
            if ($m[2][0] === '' && ! $near) {
                continue;
            }
            $value = trim((string) preg_replace('/\s+(?:IBAN|BIC|Bank|Kreditinstitut)\b.*$/iu', '', $m[3][0]), " \t:,;");
            if (mb_strlen($value) >= 2 && mb_strlen($value) <= 70 && preg_match('/\p{L}{2}/u', $value) === 1 && preg_match('/\d{4}/', $value) !== 1 && ! self::isLabel($value)) {
                return InvoiceLines::proposal($value, 0.85, $line, InvoiceLines::box($line, $m[3][1], $m[3][1] + strlen($value)));
            }
        }

        return null;
    }

    /**
     * The sender line above the address window: "Muster GmbH · Hauptstr. 1 · 12345 Musterstadt".
     *
     * @param  list<Line>  $lines
     */
    private static function senderLine(array $lines): ?InvoiceProposal
    {
        foreach (self::top($lines) as $line) {
            $parts = preg_split('/\s+[·•|]\s+|\s+-\s+/u', $line['text']) ?: [];
            if (count($parts) >= 2 && preg_match('/\b\d{5}\b/', $line['text']) === 1 && self::isName($parts[0]) && ! self::isLabel($parts[0])) {
                return InvoiceLines::proposal(trim($parts[0]), 0.75, $line, InvoiceLines::box($line, 0, strlen($parts[0])));
            }
        }

        return null;
    }

    /**
     * Point 133: the company name of the letterhead. Every name-like line at the top that is no label scores: a legal
     * form, large print (word height against the page's usual one, when positions are known) and a place high up.
     *
     * @param  list<Line>  $lines
     */
    private static function letterhead(array $lines): ?InvoiceProposal
    {
        $top = self::top($lines);
        $heights = array_values(array_filter(array_map(static fn (array $l): float => $l['h'], array_filter($lines, static fn (array $l): bool => $l['page'] === ($lines[0]['page'] ?? 1)))));
        sort($heights);
        $usual = $heights !== [] ? $heights[intdiv(count($heights), 2)] : 0.0;
        $best = null;
        foreach ($top as $rank => $line) {
            $name = null;
            $legal = false;
            if (preg_match('/^(.{2,60}?(?<![\p{L}])'.self::LEGAL.')(?=\s|$|,)/u', $line['text'], $m) === 1 && self::isName($m[1]) && ! self::isLabel($m[1])) {
                $name = trim($m[1]);
                $legal = true;
            } elseif (self::isName($line['text']) && ! self::isLabel($line['text'])) {
                $name = trim(mb_substr($line['text'], 0, 70));
            }
            if ($name === null) {
                continue;
            }
            $large = $usual > 0 && $line['h'] >= $usual * 1.3;
            $score = ($legal ? 100 : 0) + ($large ? 50 : 0) - $rank;
            if ($best === null || $score > $best[0]) {
                $best = [$score, $name, $line, $legal, $large];
            }
        }
        if ($best === null) {
            return null;
        }
        [, $name, $line, $legal, $large] = $best;
        $confidence = match (true) {
            $legal && $large => 0.75,
            $legal => 0.65,
            $large => 0.5,
            default => 0.35,
        };

        return InvoiceLines::proposal($name, $confidence, $line, InvoiceLines::box($line, 0, strlen($name)));
    }

    /**
     * The top of the first page (the letterhead area).
     *
     * @param  list<Line>  $lines
     * @return list<Line>
     */
    private static function top(array $lines): array
    {
        $page = $lines[0]['page'] ?? 1;
        $out = [];
        $skip = 0;
        foreach ($lines as $line) {
            if ($line['page'] !== $page || $line['y'] >= 0.35) {
                continue;
            }
            // The recipient's address block below "Bill to" / "Rechnungsadresse" is not the sender.
            if (preg_match('/(?<!\p{L})(?:bill(?:ed)?\s+to|ship(?:ped)?\s+to|sold\s+to|invoice\s+to|rechnungsadresse|lieferadresse|rechnungsempfänger|lieferanschrift)(?!\p{L})/iu', $line['text']) === 1) {
                $skip = 4;

                continue;
            }
            if ($skip > 0) {
                $skip--;

                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    private static function isName(string $text): bool
    {
        $text = trim($text);
        $length = mb_strlen($text);
        if ($length < 3 || $length > 70 || preg_match('/\p{L}{2}/u', $text) !== 1) {
            return false;
        }
        $digits = preg_match_all('/\d/', $text);

        return $digits <= $length * 0.3;
    }
}
