<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\TrashSettings;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Point 207: the Papierkorb's safety net – "Leerung rückgängig machen" (from the toast or the Papierkorb's ⋯ menu) and
 * the organisation's settings: how long documents stay in the Papierkorb and how long an emptying can be undone.
 * Emptying itself is DocumentBulkController::empty, deleting for good the daily TrashSweeper.
 */
final class TrashController
{
    /** One emptying (all documents with the same purge_after) back into the Papierkorb, while its undo period lasts. */
    public function unempty(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['batch' => ['required', 'date_format:Y-m-d H:i:s']]);
        $count = self::emptied($request)->where('purge_after', $data['batch'])->where('purge_after', '>', Carbon::now())
            ->update(['purge_after' => null]);
        $audit->record('document.trash_unemptied', orgId: $org->id(), userId: self::user($request)->id, meta: ['count' => $count], ip: $request->ip());
        $message = trans_choice('documents.bulk.restored', $count, ['count' => $count]);

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('documents.trash')->with('status', $message);
    }

    /** One value at a time, saved at once (Optionskarten in the Papierkorb's properties). */
    public function settings(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'keep' => ['sometimes', 'integer', Rule::in(TrashSettings::KEEP)],
            'undo' => ['sometimes', 'integer', Rule::in(TrashSettings::UNDO)],
        ]);
        $values = [];
        foreach (['keep', 'undo'] as $key) {
            if (isset($data[$key])) {
                $values[$key] = (int) $data[$key];
            }
        }
        TrashSettings::save($org->organization(), $values);

        return $request->wantsJson()
            ? response()->json(['message' => __('documents.trash_settings.saved')])
            : back()->with('status', __('documents.trash_settings.saved'));
    }

    /**
     * The emptyings still undoable that this person may see, newest first: [batch => [count, until]].
     *
     * @return list<array{batch: string, count: int, until: Carbon}>
     */
    public static function batches(Request $request): array
    {
        $rows = self::emptied($request)->where('purge_after', '>', Carbon::now())
            ->selectRaw('purge_after, count(*) as total')->groupBy('purge_after')->orderByDesc('purge_after')->toBase()->get();
        $out = [];
        foreach ($rows as $row) {
            $until = Carbon::parse((string) $row->purge_after);
            $out[] = ['batch' => $until->toDateTimeString(), 'count' => (int) $row->total, 'until' => $until];
        }

        return $out;
    }

    /** @return Builder<Document> */
    private static function emptied(Request $request): Builder
    {
        return Document::query()->visibleTo(self::user($request)->id, Gate::allows(Permission::ViewAllDocuments->value))->emptied();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
