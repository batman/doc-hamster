<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Devices\DeviceKey;
use App\Domain\Documents\ShareIntake;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * POST api/upload (points 152.2, A330): an iPhone shortcut or a program sends documents with its device
 * key (Authorization: Bearer …). No session, no cookies, so no CSRF; the key can upload and nothing else.
 */
final class UploadApiController
{
    public function __invoke(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $key = $request->bearerToken();
        $device = $key === null ? null : DeviceKey::byKey($key);
        abort_if($device === null, 401);

        // The person must still be in the organisation and allowed to upload.
        $membership = Membership::query()->where('org_id', $device->org_id)->where('user_id', $device->user_id)->whereNull('revoked_at')->first();
        abort_if($membership === null, 403);
        $org->set($membership);
        abort_unless($org->allows(Permission::UploadDocuments), 403);

        $request->validate([
            // A shortcut fed a link sends the field "file" as text; that becomes a PDF like shared text.
            'file' => ['nullable', $request->hasFile('file') ? 'file' : 'string', $request->hasFile('file') ? 'max:25600' : 'max:100000'],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:25600'],
            'title' => ['nullable', 'string', 'max:2000'],
            'text' => ['nullable', 'string', 'max:100000'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);
        $files = array_values(array_filter([$request->file('file'), ...(array) $request->file('files', [])], static fn (mixed $f): bool => $f instanceof UploadedFile));

        $source = $device->documentSource();
        $text = $request->string('text')->toString();
        if ($text === '' && ! $request->hasFile('file') && is_string($request->input('file'))) {
            $text = (string) $request->input('file');
        }
        $result = ShareIntake::store($files, $request->string('title')->toString(), $text, $request->string('url')->toString(), $device->org_id, $device->user_id, $source);
        $saved = count($result['documents']);
        if ($saved === 0) {
            return response()->json(['saved' => 0, 'skipped' => $result['skipped'], 'message' => __('devices.api_nothing')], 422);
        }

        foreach ($result['documents'] as $document) {
            $audit->record('document.uploaded', orgId: $device->org_id, userId: $device->user_id, subject: $document, meta: ['source' => $source, 'device' => $device->id], ip: $request->ip());
        }
        $device->update(['last_used_at' => now()]);

        return response()->json(['saved' => $saved, 'skipped' => $result['skipped'], 'message' => __('devices.api_saved')], 201);
    }
}
