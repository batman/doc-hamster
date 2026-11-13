<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use SimpleXMLElement;
use Throwable;

/**
 * CAMT.053 bank statements (XML, A297): no column mapping, the bank's reference (AcctSvcrRef) is the booking ID.
 * The entries become a table with a fixed header, so they run through the same import as a CSV; the own IBAN and
 * the bank's name stand in the preamble (account name, 142).
 */
final class CamtStatement
{
    /** The fixed header of the table, field by field. */
    public const HEADER = ['Buchungstag', 'Wertstellung', 'Betrag', 'Währung', 'Status', 'Bankreferenz', 'Name', 'IBAN', 'BIC', 'Verwendungszweck', 'Buchungstext', 'Mandatsreferenz', 'Gläubiger-ID', 'Konto'];

    public const FIELDS = ['date', 'value_date', 'amount', 'currency', 'state', 'booking_id', 'counterparty', 'iban', 'bic', 'purpose', 'kind', 'reference', 'creditor_id', 'sub_account'];

    public static function looksLike(string $content): bool
    {
        $head = ltrim(substr($content, 0, 1000), "\xEF\xBB\xBF \t\r\n");

        return str_starts_with($head, '<') && str_contains(substr($content, 0, 3000), 'camt.05');
    }

    public static function toStatement(string $content): ?CsvStatement
    {
        // Namespaces only get in the way of plain paths; no entities are loaded (LIBXML_NONET, no DTD).
        $xml = (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $content);
        $xml = (string) preg_replace('/<(\/?)\w+:/', '<$1', $xml);
        try {
            $root = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            return null;
        }

        $rows = [];
        $preamble = [];
        foreach ($root->xpath('//Stmt') ?: [] as $statement) {
            $iban = self::text($statement, 'Acct/Id/IBAN');
            $bank = self::text($statement, 'Acct/Svcr/FinInstnId/Nm');
            $preamble[] = trim($bank.' '.$iban);
            foreach ($statement->xpath('Ntry') ?: [] as $entry) {
                $rows[] = self::row($entry, $iban);
            }
        }

        return $rows === [] ? null : new CsvStatement(self::HEADER, $rows, implode("\n", array_filter($preamble)));
    }

    /**
     * @return list<string>
     */
    private static function row(SimpleXMLElement $entry, string $iban): array
    {
        $debit = self::text($entry, 'CdtDbtInd') === 'DBIT';
        $amount = self::text($entry, 'Amt');
        $party = $debit ? 'Cdtr' : 'Dbtr';
        $tx = $entry->xpath('NtryDtls/TxDtls')[0] ?? $entry;
        $name = self::text($tx, "RltdPties/{$party}/Nm") ?: self::text($tx, "RltdPties/{$party}/Pty/Nm");
        $purpose = implode(' ', array_map('strval', $tx->xpath('RmtInf/Ustrd') ?: [])) ?: self::text($entry, 'AddtlNtryInf');
        $status = self::text($entry, 'Sts/Cd') ?: self::text($entry, 'Sts');

        return [
            self::text($entry, 'BookgDt/Dt') ?: substr(self::text($entry, 'BookgDt/DtTm'), 0, 10),
            self::text($entry, 'ValDt/Dt') ?: substr(self::text($entry, 'ValDt/DtTm'), 0, 10),
            ($debit ? '-' : '').$amount,
            (string) ($entry->Amt['Ccy'] ?? ''),
            match ($status) {
                'BOOK' => 'completed',
                'PDNG' => 'pending',
                default => strtolower($status),
            },
            self::text($entry, 'AcctSvcrRef') ?: self::text($tx, 'Refs/AcctSvcrRef'),
            $name,
            self::text($tx, "RltdPties/{$party}Acct/Id/IBAN"),
            self::text($tx, 'RltdAgts/'.$party.'Agt/FinInstnId/BICFI') ?: self::text($tx, 'RltdAgts/'.$party.'Agt/FinInstnId/BIC'),
            $purpose,
            self::text($entry, 'AddtlNtryInf') !== $purpose ? self::text($entry, 'AddtlNtryInf') : self::text($entry, 'BkTxCd/Prtry/Cd'),
            self::text($tx, 'Refs/MndtId') ?: (self::text($tx, 'Refs/EndToEndId') === 'NOTPROVIDED' ? '' : self::text($tx, 'Refs/EndToEndId')),
            self::text($tx, 'RltdPties/Cdtr/Id/PrvtId/Othr/Id') ?: self::text($tx, 'CdtrSchmeId/Id/PrvtId/Othr/Id'),
            $iban,
        ];
    }

    private static function text(SimpleXMLElement $node, string $path): string
    {
        $found = $node->xpath($path);

        return $found === false || $found === [] || $found === null ? '' : trim((string) $found[0]);
    }
}
