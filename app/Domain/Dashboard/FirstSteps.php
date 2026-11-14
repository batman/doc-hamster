<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Devices\DeviceKey;
use App\Domain\Documents\Document;
use Illuminate\Support\Facades\DB;

/**
 * "Erste Schritte" on Home (point 213, F31): a few steps that tick themselves from the organisation's data. The card
 * shows the steps this person may do; it goes when all of them are done or the person hid it.
 *
 * @phpstan-type Step array{key: string, done: bool}
 */
final class FirstSteps
{
    /** In the order shown. */
    public const array STEPS = ['account', 'upload', 'import', 'phone'];

    /**
     * The steps to show, or null when there is no card.
     *
     * @param  array<string, bool>  $done  per step: done already
     * @param  list<string>  $allowed  the steps this person may do
     * @return list<Step>|null
     */
    public static function card(array $done, array $allowed): ?array
    {
        $steps = [];
        foreach (self::STEPS as $key) {
            if (in_array($key, $allowed, true)) {
                $steps[] = ['key' => $key, 'done' => $done[$key] ?? false];
            }
        }
        $open = array_filter($steps, static fn (array $step): bool => ! $step['done']);

        return $open === [] ? null : $steps;
    }

    /**
     * Which steps the current organisation has done (queries are org-scoped).
     *
     * @return array<string, bool>
     */
    public static function done(): array
    {
        // One query for all steps (Home's query budget): each step a sub-select of its org-scoped builder, 1 or null.
        $checks = [
            'account' => BankAccount::query(),
            'upload' => Document::withTrashed(),
            'import' => Transaction::query()->where('source', '!=', Transaction::MANUAL),
            'device' => DeviceKey::query()->whereNull('revoked_at'),
            'sent' => Document::withTrashed()->whereIn('source', ['phone', 'api']),
        ];
        $query = DB::query();
        foreach ($checks as $key => $builder) {
            $query->selectSub($builder->selectRaw('1')->limit(1), $key);
        }
        $row = (array) $query->first();

        return [
            'account' => (bool) ($row['account'] ?? false),
            'upload' => (bool) ($row['upload'] ?? false),
            'import' => (bool) ($row['import'] ?? false),
            'phone' => (bool) ($row['device'] ?? false) || (bool) ($row['sent'] ?? false),
        ];
    }
}
