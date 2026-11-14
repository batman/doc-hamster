<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Banking\Transaction;
use App\Domain\Documents\Inbox;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Menu badges: how many new things wait in an area since the person last
 * opened it. Keys are the areas; more areas join here.
 */
final class Badges
{
    /** Point 153: new in the Posteingang (sum of all sources); each tab has its own key INBOX.'-'.source. */
    public const INBOX = 'inbox';

    /** A234: new errors in Einstellungen → Log since the last visit. */
    public const LOG = 'log';

    /** A274: open bookings without a document (main menu Bank → account row → the account's "offen" filter). */
    public const BANK = 'bank';

    /**
     * @return array<string, int>
     */
    public static function counts(CurrentOrg $org): array
    {
        if (! $org->isSet()) {
            return [];
        }
        $counts = [];
        if (Gate::allows(Permission::UploadDocuments->value)) {
            $seen = [];
            foreach (Inbox::SOURCES as $source) {
                $seen[$source] = self::seen($org, self::inbox($source));
            }
            $perSource = Inbox::newCounts($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value), $seen);
            $counts[self::INBOX] = array_sum($perSource);
            foreach ($perSource as $source => $count) {
                $counts[self::inbox($source)] = $count;
            }
        }

        // A274: open bookings without a document – a plain count, not "new since the last visit": they stay until handled.
        if (Gate::allows(Permission::ImportBanking->value)) {
            $counts[self::BANK] = Transaction::query()->status(Transaction::OPEN)->count();
        }

        if (Gate::allows(Permission::ManageSettings->value)) {
            $seen = self::seen($org, self::LOG) ?? now()->subDay();
            $counts[self::LOG] = DB::table('log_entries')->where('level', 'error')->where('created_at', '>', $seen)
                ->where(static fn ($q) => $q->where('org_id', $org->id())->orWhereNull('org_id'))->count()
                + DB::table('ocr_log')->where('org_id', $org->id())->whereIn('event', OcrLog::ERRORS)->where('created_at', '>', $seen)->count();
        }

        return $counts;
    }

    /** Point 3: the badge of a settings group or entry, so the count leads down to its place (key => area). */
    public const array SETTINGS = ['log' => self::LOG];

    /** The badge key (and seen area) of one Posteingang source, e.g. "inbox-printer". */
    public static function inbox(string $source): string
    {
        return self::INBOX.'-'.$source;
    }

    /** The person opened the area(s): their badges start again from zero. */
    public static function markSeen(CurrentOrg $org, string ...$areas): void
    {
        $membership = $org->membership();
        $seen = $membership->seen_at ?? [];
        foreach ($areas as $area) {
            $seen[$area] = now()->toIso8601String();
        }
        $membership->forceFill(['seen_at' => $seen])->save();
    }

    /** When the person last opened the area (null: never). */
    public static function seen(CurrentOrg $org, string $area): ?Carbon
    {
        $value = $org->membership()->seen_at[$area] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }
}
