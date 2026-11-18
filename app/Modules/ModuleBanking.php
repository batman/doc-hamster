<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankImport;
use App\Domain\Banking\Links;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\StandingDocuments;
use App\Domain\Banking\StatementImport;
use App\Domain\Banking\StatementImporter;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Org\CurrentOrg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A97: the core's door for bank modules (Revolut first) – the same steps as the statement import, so a booking that
 * came by CSV is recognised by its bank ID and never doubled, a pending one is completed, Dauerbelege and the bank
 * matching run afterwards. Lines have the shape of StatementImport::read() (booking_date, amount_minor, currency,
 * counterparty, purpose, booking_id, bank_state, …; see docs/modules/BUILDING.md). Called through ModuleContext, which
 * checks the module's rights first.
 */
final class ModuleBanking
{
    /** The account a module keeps its bookings in, found by its own key (e.g. "revolut:<uuid>"), else made. */
    public static function account(string $sourceKey, string $name, string $currency, ?string $iban = null): BankAccount
    {
        $account = BankAccount::query()->where('source_key', $sourceKey)->first();
        if ($account !== null) {
            if ($iban !== null && $account->iban === null) {
                $account->update(['iban' => $iban]);
            }

            return $account;
        }

        return BankAccount::query()->create(['name' => mb_substr($name, 0, 100), 'currency' => strtoupper($currency), 'source_key' => $sourceKey, 'iban' => $iban]);
    }

    /** Ties an existing account (e.g. one filled by CSV before) to the module's key, so its bookings continue there. */
    public static function adopt(BankAccount $account, string $sourceKey): BankAccount
    {
        BankAccount::query()->where('source_key', $sourceKey)->whereKeyNot($account->id)->update(['source_key' => null]);
        $account->update(['source_key' => $sourceKey]);

        return $account;
    }

    /**
     * What an import would do, without storing anything (the Probelauf, A98): per line new | known | update | unclear.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{line: array<string, mixed>, action: string}>
     */
    public static function plan(?BankAccount $account, array $lines): array
    {
        return array_map(static fn (array $e): array => ['line' => $e['line'], 'action' => $e['action']], StatementImport::plan($account, $lines));
    }

    /**
     * Stores the lines like a statement import and runs what follows one (Dauerbelege, matching, "kein Beleg nötig").
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{new: int, updated: int, suggested: int}
     */
    public static function import(BankAccount $account, array $lines, string $module): array
    {
        if ($lines === []) {
            return ['new' => 0, 'updated' => 0, 'suggested' => 0];
        }
        $done = DB::transaction(static function () use ($account, $lines): array {
            $done = StatementImport::apply($account, StatementImport::plan($account, $lines));
            $dates = array_map(static fn (array $l): string => (string) $l['booking_date'], $lines);
            BankImport::query()->create(['bank_account_id' => $account->id, 'date_from' => min($dates), 'date_to' => max($dates), 'rows' => count($lines)]);

            return $done;
        });
        StatementImporter::linkRelated($account->org_id);
        $suggested = app(StandingDocuments::class)->afterImport($account->org_id, $done['new']);
        $suggested += app(Matcher::class)->afterImport($account->org_id, [...$done['new'], ...$done['completed']]);
        Links::suggestNoReceipt($done['new']);
        if ($done['new'] !== [] || $done['updated'] > 0) {
            app(AuditLog::class)->record('bank.imported', orgId: $account->org_id, subject: $account,
                meta: ['via' => 'module', 'module' => $module, 'new' => count($done['new']), 'updated' => $done['updated'], 'suggested' => $suggested]);
        }

        return ['new' => count($done['new']), 'updated' => $done['updated'], 'suggested' => $suggested];
    }

    /**
     * A receipt the bank keeps for a booking (e.g. a photo in the Revolut app) becomes a document linked to it – once:
     * a file docHamster has already (same checksum) is only linked.
     */
    public static function receipt(Transaction $transaction, string $content, string $filename, string $title): Document
    {
        $org = app(CurrentOrg::class);
        $known = DocumentFile::query()->where('sha256', hash('sha256', $content))->value('document_id');
        $document = is_string($known) ? Document::query()->find($known) : null;
        if ($document === null) {
            $path = tempnam(sys_get_temp_dir(), 'dhr');
            file_put_contents($path, $content);
            try {
                $upload = new UploadedFile($path, $filename, null, null, true);
                $type = DocumentIntake::detect($upload) ?? throw new RuntimeException('Unknown receipt type: '.$filename);
                $document = DocumentIntake::store($upload, $type[0], $type[1], $org->id(), $org->membership()->user_id, mb_substr($title, 0, 255), source: 'api');
            } finally {
                @unlink($path);
            }
        }
        if (! TransactionMatch::query()->where('transaction_id', $transaction->id)->where('document_id', $document->id)->exists()) {
            app(Links::class)->link($transaction, $document, $org->membership()->user_id);
        }

        return $document;
    }
}
