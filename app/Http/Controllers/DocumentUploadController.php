<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Http\Controllers\Concerns\ScopesDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Upload (photo or PDF): files are checked by their content and stay outside the web root (PLAN §11.2). */
final class DocumentUploadController
{
    use ScopesDocuments;

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse|JsonResponse
    {
        // One file ("file") or several from the menu ("files[]", picked or dropped).
        $data = $request->validate([
            'file' => ['required_without:files', 'file', 'max:25600'],
            'files' => ['required_without:file', 'array', 'max:20'],
            'files.*' => ['file', 'max:25600'],
            ...self::tagRules(),
            ...self::folderRule(),
        ]);

        $many = $request->file('files');
        $checked = [];
        foreach (is_array($many) ? $many : [$request->file('file')] as $upload) {
            $type = DocumentIntake::detect($upload);
            if (! $upload instanceof UploadedFile || $type === null) {
                return $request->wantsJson()
                    ? response()->json(['message' => __('documents.wrong_type')], 422)
                    : back()->withErrors(['file' => __('documents.wrong_type')]);
            }
            $checked[] = [$upload, ...$type];
        }

        $userId = $this->userId($request);
        /** @var list<string> $tagIds */
        $tagIds = array_values($data['tags'] ?? []);
        foreach ($checked as [$upload, $mime, $extension]) {
            $document = DocumentIntake::store($upload, $mime, $extension, $org->id(), $userId, $upload->getClientOriginalName(), $tagIds, folderId: $data['folder'] ?? null);

            $audit->record('document.uploaded', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
        }

        // Files dropped onto a folder row or window go there; anywhere else (main menu, other windows, the Posteingang)
        // they have no folder and wait in the Posteingang (point 181, A358). The page stays, a toast names the place.
        $folderId = $data['folder'] ?? null;
        $name = $folderId !== null ? (string) Folder::query()->whereKey($folderId)->value('name') : __('inbox.title');
        if ($request->wantsJson()) {
            return response()->json(['message' => trans_choice('documents.bulk.uploaded', count($checked), ['count' => count($checked), 'name' => $name]), 'folder' => $folderId]);
        }

        return redirect()->route($folderId !== null ? 'documents.index' : 'inbox.index')->with('status', trans_choice('documents.saved', count($checked)));
    }
}
