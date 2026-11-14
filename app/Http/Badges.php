<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Banking\Transaction;
use App\Domain\Documents\Inbox;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Http\Speed\Prefetch;
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

    /** Point 164.4: the counts stay this long in the person's session (the poll and every page read them). */
    public const int TTL = 10;

    private const string SESSION_KEY = 'badges.cache';

    /**
     * The counts, kept TTL seconds in the session per membership (no queries on most pages); markSeen() and
     * forget() drop them at once.
     *
     * @return array<string, int>
     */
    public static function counts(CurrentOrg $org): array
    {
        if (! $org->isSet()) {
            return [];
        }
        $session = request()->hasSession() ? request()->session() : null;
        $cached = $session?->get(self::SESSION_KEY);
        if (is_array($cached) && ($cached['m'] ?? null) === $org->membership()->id && ($cached['t'] ?? 0) > now()->getTimestamp() - self::TTL && is_array($cached['c'] ?? null)) {
            return $cached['c'];
        }
        $counts = self::fresh($org);
        $session?->put(self::SESSION_KEY, ['m' => $org->membership()->id, 't' => now()->getTimestamp(), 'c' => $counts]);

        return $counts;
    }

    /** Something changed that the counts show (an upload, a match …): the next read counts again. */
    public static function forget(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * @return array<string, int>
     */
    private static function fresh(CurrentOrg $org): array
    {
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
        // Point 164.8: loaded ahead of a click is not opened; the real request after it marks.
        if (Prefetch::is()) {
            return;
        }
        $membership = $org->membership();
        $seen = $membership->seen_at ?? [];
        foreach ($areas as $area) {
            $seen[$area] = now()->toIso8601String();
        }
        $membership->forceFill(['seen_at' => $seen])->save();
        self::forget();
    }

    /** When the person last opened the area (null: never). */
    public static function seen(CurrentOrg $org, string $area): ?Carbon
    {
        $value = $org->membership()->seen_at[$area] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }
}
