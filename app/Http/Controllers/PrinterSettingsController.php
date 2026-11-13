<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Printing\Printer;
use App\Domain\Printing\WindowsInstaller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einstellungen → Drucker (point 127): set up the printer "Hamster" and the
 * person's paired computers. Each person sees and deletes only their own.
 */
final class PrinterSettingsController
{
    public function index(CurrentOrg $org): View
    {
        return view('settings.printer', [
            'printers' => Printer::query()->where('user_id', $org->membership()->user_id)
                ->whereNotNull('token_hash')->whereNull('revoked_at')->oldest()->get(),
        ]);
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

    /** Löschen (126/A264): revoked and hidden; the row stays for the audit trail. */
    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $printer = Printer::query()->where('user_id', $userId)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $printer->update(['revoked_at' => now()]);
        $audit->record('printer.revoked', orgId: $org->id(), userId: $userId, subject: $printer, ip: $request->ip());

        return redirect()->route('printers.index')->with('status', __('print_tray.deleted', ['name' => $printer->name]));
    }
}
