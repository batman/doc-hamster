<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Log\LogEntry;
use App\Modules\ModuleContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Fetching from Revolut (runs in net jobs, D-020). refreshAccounts(): the accounts with their IBAN. probe(): the
 * Probelauf (A98) – what would come in, nothing stored. run(): the real import – bookings since the last fetch with 3
 * days of overlap (13 months the first time), pending ones checked again until final, then the receipts of the
 * expenses (F23) as documents linked to their booking.
 */
final class Sync
{
    public const FIRST_MONTHS = 13;

    public const OVERLAP_DAYS = 3;

    /** True while a fetch runs – the receipts it stores must not start another fetch (Module's upload listener). */
    public static bool $running = false;

    public function __construct(private readonly ModuleContext $context, private readonly Settings $settings, private readonly Client $client) {}

    /** @return list<array{id: string, name: string, currency: string, iban: ?string, state: string}> */
    public function refreshAccounts(): array
    {
        $accounts = [];
        foreach ($this->client->accounts() as $a) {
            if (! is_string($a['id'] ?? null)) {
                continue;
            }
            $iban = null;
            try {
                foreach ($this->client->bankDetails($a['id']) as $detail) {
                    $iban ??= is_string($detail['iban'] ?? null) ? str_replace(' ', '', $detail['iban']) : null;
                }
            } catch (RuntimeException) {
                // Without bank details the account is still usable.
            }
            $accounts[] = ['id' => $a['id'], 'name' => (string) ($a['name'] ?? 'Revolut'), 'currency' => strtoupper((string) ($a['currency'] ?? 'EUR')),
                'iban' => $iban, 'state' => (string) ($a['state'] ?? 'active')];
        }
        $this->settings->save(['accounts' => json_encode($accounts), 'last_error' => null]);

        return $accounts;
    }

    /**
     * A98: the last weeks as they would come in – per account the lines with "neu" / "schon da" / "wird ergänzt".
     *
     * @return list<array{account: array<string, mixed>, target: string, new: int, known: int, rows: list<array{line: array<string, mixed>, action: string}>}>
     */
    public function probe(int $days = 60): array
    {
        $byAccount = $this->fetch(now()->subDays($days));
        $out = [];
        foreach ($this->settings->accounts() as $account) {
            $lines = array_values($byAccount[$account['id']] ?? []);
            $target = $this->target($account['id']);
            $plan = $this->context->planBookings($target, $lines);
            $out[] = ['account' => $account, 'target' => $target !== null ? $target->name : __('m-revolut::texts.new_account', ['name' => self::name($account)]),
                'new' => count(array_filter($plan, static fn (array $e): bool => $e['action'] === 'new')),
                'known' => count(array_filter($plan, static fn (array $e): bool => $e['action'] !== 'new')),
                'rows' => array_slice($plan, 0, 25)];
        }

        return $out;
    }

    /** @return array{new: int, updated: int, suggested: int, receipts: int} */
    public function run(): array
    {
        self::$running = true;
        try {
            return $this->fetchAll();
        } finally {
            self::$running = false;
        }
    }

    /** @return array{new: int, updated: int, suggested: int, receipts: int} */
    private function fetchAll(): array
    {
        $started = now();
        $from = $this->settings->time('synced_until')?->copy()->subDays(self::OVERLAP_DAYS) ?? now()->subMonths(self::FIRST_MONTHS);
        $byAccount = $this->fetch($from);
        $byAccount = $this->pending($byAccount);
        $total = ['new' => 0, 'updated' => 0, 'suggested' => 0, 'receipts' => 0];
        foreach ($this->settings->accounts() as $account) {
            $lines = array_values($byAccount[$account['id']] ?? []);
            if ($lines === []) {
                continue;
            }
            $done = $this->context->importBookings($this->account($account), $lines);
            foreach (['new', 'updated', 'suggested'] as $k) {
                $total[$k] += $done[$k];
            }
        }
        $total['receipts'] = $this->receipts($this->settings->time('receipts_until')?->copy()->subDays(self::OVERLAP_DAYS) ?? $from);
        $this->settings->save(['synced_at' => $started, 'synced_until' => $started, 'receipts_until' => $started, 'last_error' => null]);
        if ($total['new'] > 0 || $total['receipts'] > 0) {
            LogEntry::write('general', 'event', (string) __('m-revolut::texts.log_sync', $total), 'module.revolut.sync');
        }

        return $total;
    }

    /** @return array<string, array<string, array<string, mixed>>> Revolut account id => booking id+leg => line */
    private function fetch(Carbon $from): array
    {
        $byAccount = [];
        foreach ($this->client->transactions($from->copy()->utc()->format('Y-m-d\TH:i:s\Z')) as $t) {
            foreach (Mapper::lines($t) as $accountId => $line) {
                $byAccount[$accountId][$line['fingerprint']] = $line;
            }
        }

        return $byAccount;
    }

    /**
     * Pending bookings older than the window are fetched one by one until they are final (a card hold can take weeks).
     *
     * @param  array<string, array<string, array<string, mixed>>>  $byAccount
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function pending(array $byAccount): array
    {
        $ids = Transaction::query()->whereIn('bank_account_id', BankAccount::query()->where('source_key', 'like', 'revolut:%')->select('id'))
            ->whereIn('bank_state', ['pending', 'created'])->whereNotNull('booking_id')->limit(200)->pluck('booking_id')->unique();
        foreach ($ids as $id) {
            try {
                foreach (Mapper::lines($this->client->transaction((string) $id)) as $accountId => $line) {
                    $byAccount[$accountId][$line['fingerprint']] = $line;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        return $byAccount;
    }

    /** F23: receipts of expenses → documents linked to their booking; each receipt once (m_revolut_receipts). */
    private function receipts(Carbon $from): int
    {
        $count = 0;
        foreach ($this->client->expenses($from->copy()->utc()->format('Y-m-d\TH:i:s\Z')) as $expense) {
            $transactionId = $expense['transaction_id'] ?? null;
            if (! is_string($transactionId) || ! is_array($expense['receipt_ids'] ?? null)) {
                continue;
            }
            $booking = Transaction::query()->where('booking_id', $transactionId)
                ->whereIn('bank_account_id', BankAccount::query()->where('source_key', 'like', 'revolut:%')->select('id'))->first();
            if ($booking === null) {
                continue;
            }
            foreach ($expense['receipt_ids'] as $receiptId) {
                if (! is_string($receiptId) || DB::table('m_revolut_receipts')->where('receipt_id', $receiptId)->exists()) {
                    continue;
                }
                try {
                    $file = $this->client->receipt((string) $expense['id'], $receiptId);
                    $extension = match ($file['type']) {
                        'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', default => 'jpg',
                    };
                    $title = trim(__('m-revolut::texts.receipt_title', ['merchant' => (string) ($expense['merchant'] ?? $booking->counterparty ?? 'Revolut'),
                        'date' => Carbon::parse((string) $expense['expense_date'])->format('d.m.Y')]));
                    $document = $this->context->attachReceipt($booking, $file['content'], 'revolut-'.$receiptId.'.'.$extension, $title);
                    DB::table('m_revolut_receipts')->insert(['receipt_id' => $receiptId, 'org_id' => $booking->org_id, 'document_id' => $document->id, 'created_at' => now()]);
                    $count++;
                } catch (Throwable $e) {
                    LogEntry::write('general', 'warning', (string) __('m-revolut::texts.receipt_failed', ['reason' => mb_substr($e->getMessage(), 0, 200)]), 'module.revolut.receipt');
                }
            }
        }

        return $count;
    }

    /**
     * The docHamster account for a Revolut account: the one the person chose, else one of the module's own.
     *
     * @param  array{id: string, name: string, currency: string, iban: ?string, state: string}  $account
     */
    private function account(array $account): BankAccount
    {
        $chosen = $this->settings->mapping()[$account['id']] ?? 'new';
        $existing = $this->context->findBankAccount($account['id']);
        if ($existing !== null) {
            return $existing;
        }
        if ($chosen !== 'new' && ($picked = BankAccount::query()->find($chosen)) !== null) {
            return $this->context->adoptBankAccount($picked, $account['id']);
        }

        return $this->context->bankAccount($account['id'], self::name($account), $account['currency'], $account['iban']);
    }

    /** For the Probelauf: the account the lines would go to, if it is there already (null = a new one). */
    private function target(string $revolutId): ?BankAccount
    {
        $chosen = $this->settings->mapping()[$revolutId] ?? 'new';

        return $this->context->findBankAccount($revolutId) ?? ($chosen !== 'new' ? BankAccount::query()->find($chosen) : null);
    }

    /** @param array<string, mixed> $account */
    public static function name(array $account): string
    {
        $name = trim((string) ($account['name'] ?? ''));

        return 'Revolut '.($name !== '' && strcasecmp($name, 'main') !== 0 ? $name.' ' : '').$account['currency'];
    }
}
