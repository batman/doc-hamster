<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Log\LogEntry;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\RoleRestriction;
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
        // F39: without a Posteingang a folder-restricted role's upload lands in its first folder.
        $data['folder'] ??= RoleRestriction::current()->homeFolder();
        /** @var list<string> $tagIds */
        $tagIds = array_values($data['tags'] ?? []);
        $known = [];
        foreach ($checked as [$upload, $mime, $extension]) {
            // A80: the same file is here already – still stored (the duplicate becomes a proposal, A49), but named in the upload's protocol.
            if (DocumentFile::query()->where('sha256', (string) hash_file('sha256', $upload->getRealPath()))->exists()) {
                $known[] = $upload->getClientOriginalName();
            }
            $document = DocumentIntake::store($upload, $mime, $extension, $org->id(), $userId, $upload->getClientOriginalName(), $tagIds, folderId: $data['folder'] ?? null);

            $audit->record('document.uploaded', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
        }

        // Files dropped onto a folder row or window go there; anywhere else (main menu, other windows, the Posteingang)
        // they have no folder and wait in the Posteingang (point 181, A358). The page stays, a toast names the place.
        $folderId = $data['folder'] ?? null;
        $name = $folderId !== null ? (string) Folder::query()->whereKey($folderId)->value('name') : __('inbox.title');
        if ($request->wantsJson()) {
            return response()->json(['message' => trans_choice('documents.bulk.uploaded', count($checked), ['count' => count($checked), 'name' => $name]), 'folder' => $folderId, 'known' => $known]);
        }

        return redirect()->route($folderId !== null ? 'documents.index' : 'inbox.index')->with('status', trans_choice('documents.saved', count($checked)));
    }

    /**
     * Points 31, A84: the Hochlade-Dialog files its protocol in the Logbuch – uploads of two or more files, or any with a
     * problem. The short line counts, the long part (A82) lists every file; written here from the names the browser
     * sends, so the texts are the server's own.
     */
    public function protocol(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'done' => ['nullable', 'array', 'max:5000'], 'done.*' => ['string', 'max:300'],
            'known' => ['nullable', 'array', 'max:5000'], 'known.*' => ['string', 'max:300'],
            'failed' => ['nullable', 'array', 'max:5000'], 'failed.*' => ['string', 'max:600'],
        ]);
        [$done, $known, $failed] = [array_values($data['done'] ?? []), array_values($data['known'] ?? []), array_values($data['failed'] ?? [])];
        $line = __('upload.log.line', ['done' => count($done), 'failed' => count($failed), 'known' => count($known), 'name' => $data['name'] ?? '']);
        $block = static fn (string $head, array $names): array => $names === [] ? [] : ['', $head.':', ...array_map(static fn (string $n): string => '- '.$n, $names)];
        $body = implode("\n", [...$block(__('upload.log.done'), $done), ...$block(__('upload.log.failed'), $failed), ...$block(__('upload.log.known'), $known)]);
        LogEntry::write('general', $failed === [] ? 'event' : 'warning', $line, 'document.upload_batch', body: ltrim($body));

        return response()->json(['message' => __('upload.logged'), 'log' => route('log.index')]);
    }
}
