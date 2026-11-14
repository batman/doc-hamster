<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Documents\ShareIntake;
use App\Domain\Documents\Tag;
use App\Domain\Org\CurrentOrg;
use App\Http\Controllers\Concerns\ScopesDocuments;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Teilen → DocHamster" on Android (points 151, A328): the installed app is a share target. The service
 * worker (public/sw.js) keeps what was shared and this page sends it after login (151.3); every file is
 * stored at once ("Photo, done"), then a short confirmation with an optional folder and tags.
 */
final class ShareTargetController
{
    use ScopesDocuments;

    private const SESSION = 'share.last';

    /** The page the service worker sends the phone to: it replays the kept share (modules/share.js). */
    public function receive(): View
    {
        return view('share.receive');
    }

    /** From the page (fetch with CSRF) or, without service worker, straight from the share sheet. */
    public function store(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate([
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:25600'],
            'title' => ['nullable', 'string', 'max:2000'],
            'text' => ['nullable', 'string', 'max:100000'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);
        $userId = $this->userId($request);
        $files = $request->file('files');

        $result = ShareIntake::store(
            is_array($files) ? array_values($files) : [],
            $request->string('title')->toString(),
            $request->string('text')->toString(),
            $request->string('url')->toString(),
            $org->id(),
            $userId,
            'phone',
        );
        foreach ($result['documents'] as $document) {
            $audit->record('document.uploaded', orgId: $org->id(), userId: $userId, subject: $document, meta: ['source' => 'phone'], ip: $request->ip());
        }

        // Several kept shares sent in one go (append=1 after the first) end on one confirmation page.
        $ids = array_map(static fn (Document $d): string => $d->id, $result['documents']);
        $skipped = $result['skipped'];
        $previous = $request->session()->get(self::SESSION);
        if ($request->boolean('append') && is_array($previous)) {
            $ids = [...(array) ($previous['ids'] ?? []), ...$ids];
            $skipped += (int) ($previous['skipped'] ?? 0);
        }
        $request->session()->put(self::SESSION, ['ids' => $ids, 'skipped' => $skipped]);

        return $request->wantsJson()
            ? response()->json(['saved' => count($ids), 'skipped' => $result['skipped'], 'redirect' => route('share.done')])
            : redirect()->route('share.done');
    }

    /** "N Dokumente gespeichert": thumbnails, folder and tags of the last share. */
    public function done(Request $request): View
    {
        $last = $request->session()->get(self::SESSION, ['ids' => [], 'skipped' => 0]);
        $documents = $this->lastDocuments($request);
        $first = $documents->first();

        return view('share.done', [
            'documents' => $documents,
            'skipped' => (int) ($last['skipped'] ?? 0),
            'folders' => Folder::flat(),
            'folderId' => $first?->folder_id,
            'tags' => Tag::query()->orderBy('name')->get(),
            'selected' => $first?->tags->pluck('id')->all() ?? [],
        ]);
    }

    /** Autosave of the confirmation page: folder and tags for every document of the last share. */
    public function update(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([...self::folderRule(), ...self::tagRules()]);
        $userId = $this->userId($request);
        $folderId = $data['folder'] ?? Folder::defaultIdFor($org->id());

        foreach ($this->lastDocuments($request) as $document) {
            $document->update(['folder_id' => $folderId]);
            $document->tags()->sync($data['tags'] ?? []);
            $audit->record('document.changed', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
        }

        return $request->wantsJson()
            ? response()->json(['message' => __('share.changed')])
            : redirect()->route('share.done');
    }

    /**
     * @return Collection<int, Document>
     */
    private function lastDocuments(Request $request): Collection
    {
        $ids = $request->session()->get(self::SESSION.'.ids', []);

        return $this->visible($request)->whereKey(is_array($ids) ? $ids : [])->with(['files', 'tags'])->oldest()->get();
    }
}
