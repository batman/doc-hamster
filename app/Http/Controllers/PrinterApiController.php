<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Permission;
use App\Domain\Printing\Printer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * What the computer with the printer "Hamster" talks to: linking it once
 * with the code from the download, then sending printed documents with its
 * token. No session and no cookies, so no CSRF; the token is the only key.
 */
final class PrinterApiController
{
    public function pair(Request $request, AuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $name = trim((string) ($data['name'] ?? ''));

        $paired = Printer::pair($data['code'], $name !== '' ? $name : 'Windows-PC');
        abort_if($paired === null, 404);
        [$printer, $token] = $paired;

        $audit->record('printer.paired', orgId: $printer->org_id, userId: $printer->user_id, subject: $printer, ip: $request->ip());

        return response()->json(['token' => $token], 201);
    }

    public function upload(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $token = $request->bearerToken();
        $printer = $token === null ? null : Printer::byToken($token);
        abort_if($printer === null, 401);

        // The person must still be in the organisation and allowed to upload.
        $membership = Membership::query()->where('org_id', $printer->org_id)->where('user_id', $printer->user_id)->whereNull('revoked_at')->first();
        abort_if($membership === null, 403);
        $org->set($membership);
        abort_unless($org->allows(Permission::UploadDocuments), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:25600'],
            'title' => ['nullable', 'string', 'max:1000'],
        ]);
        $upload = $request->file('file');
        $type = DocumentIntake::detect($upload);
        abort_if(! $upload instanceof UploadedFile || $type === null, 422);

        $title = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($data['title'] ?? '')));
        $title = $title !== '' ? $title : __('print_tray.default_title', ['date' => now()->format('d.m.Y H:i')]);

        $document = DocumentIntake::store($upload, $type[0], $type[1], $printer->org_id, $printer->user_id, $title, inPrintTray: true);
        $printer->update(['last_used_at' => now()]);
        $audit->record('document.printed', orgId: $printer->org_id, userId: $printer->user_id, subject: $document, meta: ['printer' => $printer->id], ip: $request->ip());

        return response()->json(['id' => $document->id], 201);
    }
}
