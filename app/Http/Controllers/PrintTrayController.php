<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Printing\Printer;
use App\Http\Badges;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Druckerablage: what was printed on "Hamster" waits here until it is
 * taken over as a receipt or deleted. The printers themselves are set up in
 * Einstellungen → Drucker (point 127).
 */
final class PrintTrayController
{
    public function index(CurrentOrg $org): View
    {
        Badges::markSeen($org, Badges::PRINT_TRAY);

        return view('print-tray.index', [
            'documents' => $this->tray($org)->with('files')->latest()->limit(200)->get(),
            // A266: until a computer is paired, the empty tray points to Einstellungen → Drucker.
            'hasPrinter' => Printer::query()->where('user_id', $org->membership()->user_id)
                ->whereNotNull('token_hash')->whereNull('revoked_at')->exists(),
            'canDelete' => Gate::allows(Permission::TrashDocuments->value),
        ]);
    }

    /** Als Beleg übernehmen: from now on it is a receipt like every other. */
    public function accept(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $document = $this->tray($org)->whereKey($id)->firstOrFail();
        $document->forceFill(['in_print_tray' => false])->save();
        $audit->record('document.taken_over', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, ip: $request->ip());

        return redirect()->route('print-tray.index')->with('status', __('print_tray.accepted', ['title' => $document->title]));
    }

    /** Into the trash; restored, it comes back to the print tray. */
    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $document = $this->tray($org)->whereKey($id)->firstOrFail();
        $document->delete();
        $audit->record('document.trashed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, ip: $request->ip());

        return redirect()->route('print-tray.index')->with('status', __('documents.deleted'));
    }

    /**
     * @return Builder<Document>
     */
    private function tray(CurrentOrg $org): Builder
    {
        return Document::query()->inPrintTray()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value));
    }
}
