<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\Inbox;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Printing\Printer;
use App\Http\Badges;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Posteingang (point 153, A327): one window with everything that came in – uploads, the printer "Hamster",
 * e-mail, shared from a phone, other programs – newest first, until it is opened, moved or marked "Erledigt".
 * Tabs per source with their own red count; viewing a tab marks that source seen ("Alle": every source).
 */
final class InboxController
{
    public function index(Request $request, CurrentOrg $org): View
    {
        $source = $this->source($request);
        Badges::markSeen($org, ...array_map(Badges::inbox(...), $source !== null ? [$source] : Inbox::SOURCES));

        return view('inbox.index', [
            'source' => $source,
            'documents' => $this->inbox($org, $source)->with(['files', 'tags'])->latest()->limit(200)->get(),
            'badges' => Badges::counts($org),
            // A266: until a computer is paired, the empty printer tab points to Einstellungen → Eingang → Drucker.
            'hasPrinter' => $source !== 'printer' || Printer::query()->where('user_id', $org->membership()->user_id)
                ->whereNotNull('token_hash')->whereNull('revoked_at')->exists(),
        ]);
    }

    /** Row ✓ "Erledigt": the document stays where it is, it only leaves the inbox. */
    public function done(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $document = $this->inbox($org)->whereKey($id)->firstOrFail();
        $document->forceFill(['inbox_done_at' => now()])->save();
        $audit->record('document.inbox_done', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, ip: $request->ip());

        return $this->answer($request, __('inbox.done_one', ['title' => $document->title]));
    }

    /** "Alle erledigt": everything of the open tab (or of every source) leaves the inbox. */
    public function doneAll(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $source = $this->source($request);
        $count = $this->inbox($org, $source)->update(['inbox_done_at' => now()]);
        $audit->record('document.inbox_done_all', orgId: $org->id(), userId: $org->membership()->user_id, meta: ['source' => $source, 'count' => $count], ip: $request->ip());

        return $this->answer($request, trans_choice('inbox.done_all_status', $count, ['count' => $count]), $source);
    }

    private function answer(Request $request, string $message, ?string $source = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }
        $source ??= $this->source($request);

        return redirect()->route('inbox.index', $source !== null ? ['source' => $source] : [])->with('status', $message);
    }

    private function source(Request $request): ?string
    {
        $source = $request->input('source');

        return is_string($source) && in_array($source, Inbox::SOURCES, true) ? $source : null;
    }

    /**
     * @return Builder<Document>
     */
    private function inbox(CurrentOrg $org, ?string $source = null): Builder
    {
        return Inbox::query($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value), $source);
    }
}
