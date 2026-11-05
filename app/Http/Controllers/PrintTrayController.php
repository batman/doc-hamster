<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Printing\Printer;
use App\Domain\Printing\WindowsInstaller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Druckerablage: what was printed on "Hamster" waits here until it is
 * taken over as a receipt or deleted; the person's printers are set up and
 * locked here too.
 */
final class PrintTrayController
{
    public function index(CurrentOrg $org): View
    {
        return view('print-tray.index', [
            'documents' => $this->tray($org)->with('files')->latest()->limit(200)->get(),
            'printers' => Printer::query()->where('user_id', $org->membership()->user_id)
                ->whereNotNull('token_hash')->whereNull('revoked_at')->oldest()->get(),
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

    /** "Drucker einrichten": the installer for Windows, linked to this person with a one-time code. */
    public function setup(Request $request, AuditLog $audit, CurrentOrg $org): Response
    {
        $userId = $org->membership()->user_id;
        [$printer, $code] = Printer::startPairing($userId, __('print_tray.new_printer'));
        $audit->record('printer.setup_started', orgId: $org->id(), userId: $userId, subject: $printer, ip: $request->ip());

        return response(WindowsInstaller::build((string) config('app.url'), $code), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.WindowsInstaller::FILENAME.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Sperren: the computer can't send anything any more. */
    public function revoke(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $printer = Printer::query()->where('user_id', $userId)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $printer->update(['revoked_at' => now()]);
        $audit->record('printer.revoked', orgId: $org->id(), userId: $userId, subject: $printer, ip: $request->ip());

        return redirect()->route('print-tray.index')->with('status', __('print_tray.revoked', ['name' => $printer->name]));
    }

    /**
     * @return Builder<Document>
     */
    private function tray(CurrentOrg $org): Builder
    {
        return Document::query()->inPrintTray()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value));
    }
}
