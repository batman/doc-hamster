<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\ReceiptArchive;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ZIP export per period (MVP). Asks for the password again: the matrix wants a
 * confirmation for exports (PLAN §2.1) and passkey step-up doesn't exist yet.
 */
final class ExportController
{
    public function form(): View
    {
        return view('exports.form', [
            'from' => now()->startOfMonth()->subMonth()->format('Y-m-d'),
            'to' => now()->startOfMonth()->subDay()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse|BinaryFileResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'password' => ['required', 'current_password'],
        ]);

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // A role may have "Exportieren" without "Alle Belege sehen": then only the own ones (#30).
        $documents = Document::query()
            ->visibleTo($user->id, Gate::allows(Permission::ViewAllDocuments->value))
            ->with('files')
            ->whereBetween('created_at', [$from, $to])
            ->oldest()
            ->get();

        if ($documents->isEmpty()) {
            return back()->withInput($request->except('password'))->withErrors(['from' => __('exports.empty')]);
        }

        @set_time_limit(300);
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $target = $dir.'/'.Str::lower((string) Str::ulid()).'.zip';
        ReceiptArchive::write($documents, $target);

        $audit->record('export.created', orgId: $org->id(), userId: $user->id, meta: [
            'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'documents' => $documents->count(),
        ], ip: $request->ip());

        return response()->download($target, sprintf('belege_%s_bis_%s.zip', $from->format('Y-m-d'), $to->format('Y-m-d')))
            ->deleteFileAfterSend();
    }
}
