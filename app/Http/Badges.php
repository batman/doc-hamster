<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Documents\Document;
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
    public const PRINT_TRAY = 'print-tray';

    /** A234: new errors in Einstellungen → Log since the last visit. */
    public const LOG = 'log';

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
            $seen = self::seen($org, self::PRINT_TRAY);
            $counts[self::PRINT_TRAY] = Document::query()->inPrintTray()
                ->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))
                ->when($seen !== null, fn ($query) => $query->where('created_at', '>', $seen))
                ->count();
        }

        if (Gate::allows(Permission::ManageSettings->value)) {
            $seen = self::seen($org, self::LOG) ?? now()->subDay();
            $counts[self::LOG] = DB::table('log_entries')->where('level', 'error')->where('created_at', '>', $seen)
                ->where(static fn ($q) => $q->where('org_id', $org->id())->orWhereNull('org_id'))->count()
                + DB::table('ocr_log')->where('org_id', $org->id())->whereIn('event', OcrLog::ERRORS)->where('created_at', '>', $seen)->count();
        }

        return $counts;
    }

    /** The person opened the area: its badge starts again from zero. */
    public static function markSeen(CurrentOrg $org, string $area): void
    {
        $membership = $org->membership();
        $seen = $membership->seen_at ?? [];
        $seen[$area] = now()->toIso8601String();
        $membership->forceFill(['seen_at' => $seen])->save();
    }

    private static function seen(CurrentOrg $org, string $area): ?Carbon
    {
        $value = $org->membership()->seen_at[$area] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }
}
