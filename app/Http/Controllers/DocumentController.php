<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Tag;
use App\Domain\Documents\TextDocument;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Organization;
use App\Domain\Org\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * MVP receipts: list, upload (photo or PDF), protected file delivery.
 * Files are checked by their content and stay outside the web root (PLAN §11.2).
 */
final class DocumentController
{
    /** The views of the list, like the Windows Explorer (ui-redesign point 10), with their icon. */
    public const VIEWS = [
        'details' => 'list-details',
        'list' => 'list',
        'small' => 'layout-grid',
        'large' => 'layout-cards',
        'timeline' => 'timeline',
        'calendar' => 'calendar',
    ];

    private const LIMIT = 200;

    public function index(Request $request): View
    {
        return view('documents.index', $this->listing($request));
    }

    /** The list with one receipt opened beside it (design point 17), with its own address. */
    public function show(Request $request, string $id): View
    {
        $open = $this->visible($request)->with(['files', 'tags', 'matches.transaction'])->whereKey($id)->firstOrFail();

        return view('documents.index', [...$this->listing($request), 'open' => $open]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(Request $request): array
    {
        $tags = Tag::query()->orderBy('name')->get();
        $wanted = $request->query('tags');
        $selected = $tags->pluck('id')->intersect(is_array($wanted) ? $wanted : [])->values()->all();

        $query = Document::query()
            ->visibleTo($this->userId($request), Gate::allows(Permission::ViewAllDocuments->value))
            ->with(['files', 'tags']);

        // With several tags, a receipt must have all of them.
        foreach ($selected as $tagId) {
            $query->whereHas('tags', static fn ($tag) => $tag->whereKey($tagId));
        }

        // Search in the window head: title, note or tag name (A104).
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(static fn ($w) => $w->where('title', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhere('ocr_text', 'like', $like)
                ->orWhereHas('tags', static fn ($tag) => $tag->where('name', 'like', $like)));
        }

        // Like an explorer (points 10-12): a folder shows only its own receipts, the top level only those
        // without a folder; a search looks through everything below the current folder.
        $folders = Folder::flat();
        $wantedFolder = $request->query('folder');
        $folder = is_string($wantedFolder) && collect($folders)->contains(static fn (array $row): bool => $row['folder']->id === $wantedFolder) ? $wantedFolder : null;
        // The view belongs to the window (point 52): ?view=… in its address; a window without one starts with the
        // view the person chose last (receipt_view).
        $wantedView = $request->query('view');
        $saved = $this->user($request)->receipt_view;
        $view = is_string($wantedView) && isset(self::VIEWS[$wantedView]) ? $wantedView
            : ($saved !== null && isset(self::VIEWS[$saved]) ? $saved : 'details');

        // Calendar, timeline and the tag filter look, like a search, through everything below the current folder.
        if ($q !== '' || $selected !== [] || in_array($view, ['calendar', 'timeline'], true)) {
            if ($folder !== null) {
                $query->whereIn('folder_id', Folder::branchIds($folder));
            }
        } elseif ($folder !== null) {
            $query->where('folder_id', $folder);
        } else {
            $query->whereNull('folder_id');
        }

        $month = null;
        if ($view === 'calendar') {
            $wanted = $request->query('month');
            $parsed = is_string($wanted) && preg_match('/^\d{4}-\d{2}$/', $wanted) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $wanted) : null;
            $month = $parsed instanceof CarbonImmutable ? $parsed : CarbonImmutable::now()->startOfMonth();
            $documents = $query->whereBetween('created_at', [$month, $month->endOfMonth()])->oldest()->get();
        } else {
            $documents = $query->latest()->limit(self::LIMIT + 1)->get();
        }
        // Filtering in the browser works only when the page holds every receipt (point 7).
        $complete = $selected === [] && $q === '' && ($view === 'calendar' || $documents->count() <= self::LIMIT);

        return [
            'open' => null,
            'documents' => $view === 'calendar' ? $documents : $documents->take(self::LIMIT),
            'tags' => $tags,
            'selected' => $selected,
            'folders' => $folders,
            'folder' => $folder,
            'folderRows' => $this->folderRows($request, $folders, $folder),
            'view' => $view,
            'views' => self::VIEWS,
            'month' => $month,
            'complete' => $complete,
            'trashed' => Gate::allows(Permission::TrashDocuments->value) ? $this->visible($request)->withPrintTray()->onlyTrashed()->count() : 0,
            'tree' => Folder::tree($folders),
            'trashName' => Folder::trashName($this->organization()),
            'trashColor' => Folder::trashColor($this->organization()),
        ];
    }

    /**
     * The chosen view is the default for new windows (point 11); the window it was chosen in shows it via
     * ?view=… (point 52). `back` is that window's address (only this app's own addresses count).
     */
    public function setView(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'view' => ['required', Rule::in(array_keys(self::VIEWS))],
            'back' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->user($request)->forceFill(['receipt_view' => $data['view']])->save();

        $root = url('/');
        $back = is_string($data['back'] ?? null) && str_starts_with($data['back'].'/', $root.'/') ? $data['back'] : url()->previous(route('documents.index'));
        if (! str_starts_with($back.'/', $root.'/')) {
            $back = route('documents.index');
        }
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        unset($query['page']);
        $query['view'] = $data['view'];

        return redirect()->to(explode('?', $back, 2)[0].'?'.http_build_query($query));
    }

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

        // Files dropped onto a folder row, a folder window or the main menu (point 85): the page stays, a toast names the folder.
        if ($request->wantsJson()) {
            $folderId = $data['folder'] ?? Folder::defaultIdFor($org->id());
            $name = (string) Folder::query()->whereKey($folderId)->value('name');

            return response()->json(['message' => trans_choice('documents.bulk.uploaded', count($checked), ['count' => count($checked), 'name' => $name]), 'folder' => $folderId]);
        }

        return redirect()->route('documents.index')->with('status', trans_choice('documents.saved', count($checked)));
    }

    public function updateTags(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(self::tagRules());
        $userId = $this->userId($request);

        $document = Document::query()
            ->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value))
            ->whereKey($id)
            ->firstOrFail();

        $document->tags()->sync($data['tags'] ?? []);
        $audit->record('document.tags_changed', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());

        return back()->with('status', __('tags.changed'));
    }

    /** Title, note and tags from the receipt view. */
    public function update(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'note' => ['nullable', 'string', 'max:5000'],
            ...self::tagRules(),
            ...self::folderRule(),
        ]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();

        // The folder changes only when the form has the field, so other forms never clear it.
        // The note is edited in place now (click-to-edit), so the form only changes it when it sends it.
        $document->update([
            ...($request->has('note') ? ['note' => $data['note'] ?? null] : []),
            ...(isset($data['title']) ? ['title' => $data['title']] : []),
            ...($request->has('folder') ? ['folder_id' => $data['folder'] ?? Folder::defaultIdFor($org->id())] : []),
        ]);
        $document->tags()->sync($data['tags'] ?? []);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return back()->with('status', __('documents.changed'));
    }

    /**
     * One text field edited in place (click-to-edit, points 27/28): the note or the recognised text.
     * Answers with JSON, the page stays as it is.
     */
    public function updateField(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in(['note', 'ocr_text', 'title'])],
            'value' => [...match ($request->input('field')) {
                'note' => ['nullable', 'max:5000'],
                // Renamed in its row (pencil, A174): a document always keeps a name.
                'title' => ['required', 'max:250'],
                default => ['nullable', 'max:1000000'],
            }, 'string'],
        ]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $value = trim((string) ($data['value'] ?? ''));
        if ($data['field'] === 'title' && $value === '') {
            return response()->json(['message' => __('validation.required', ['attribute' => __('documents.name')])], 422);
        }

        $document->forceFill([$data['field'] => $value !== '' ? $value : null])->save();
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return response()->json(['value' => $value, 'message' => __('documents.changed')]);
    }

    /** "In Ordner verschieben" from the ⋯ menu of the receipt window (point 26): only the folder changes. */
    public function move(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(['folder' => ['required', ...self::folderRule()['folder']]]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();

        $document->update(['folder_id' => $data['folder']]);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return back()->with('status', __('documents.moved'));
    }

    /** Into the trash: the receipt leaves every list, the file stays (#30, "Belege löschen"). */
    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $document->delete();
        $audit->record('document.trashed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return redirect()->route('documents.index')->with('status', __('documents.deleted'));
    }

    /**
     * The trash (#30 "Belege löschen"): receipts deleted by mistake come back
     * from here; something from the print tray goes back there.
     */
    public function trash(Request $request): View
    {
        return view('documents.trash', [
            'documents' => $this->visible($request)->withPrintTray()->onlyTrashed()->with('files')->latest('deleted_at')->limit(200)->get(),
            'count' => $this->visible($request)->withPrintTray()->onlyTrashed()->count(),
            'name' => Folder::trashName($this->organization()),
        ]);
    }

    private function organization(): ?Organization
    {
        $org = app(CurrentOrg::class);

        return $org->isSet() ? $org->organization() : null;
    }

    public function restore(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse|JsonResponse
    {
        $document = $this->visible($request)->withPrintTray()->onlyTrashed()->whereKey($id)->firstOrFail();
        $document->restore();
        $audit->record('document.restored', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());
        if ($request->wantsJson()) {
            return response()->json(['message' => __('documents.restored')]);
        }

        return redirect()->route('documents.trash')->with('status', __('documents.restored'));
    }

    public function file(Request $request, string $id): Response
    {
        [$file, $document] = $this->visibleFile($request, $id);
        $document->forceFill(['last_opened_at' => now()])->saveQuietly();
        // Text documents are shown as plain text, never as a page of this site (point 21).
        $type = TextDocument::isText($file) ? 'text/plain; charset=UTF-8' : $file->mime;

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function download(Request $request, string $id): Response
    {
        [$file, $document] = $this->visibleFile($request, $id);
        $document->forceFill(['last_downloaded_at' => now()])->saveQuietly();

        return Storage::disk('local')->download($file->path, $file->downloadName($document->title), ['Content-Type' => $file->mime]);
    }

    /** The stored small image; it never changes, so the browser may keep it. */
    public function thumb(Request $request, string $id): Response
    {
        [$file] = $this->visibleFile($request, $id);
        abort_if($file->thumb_path === null, 404);

        return Storage::disk('local')->response($file->thumb_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * @return array{DocumentFile, Document}
     */
    private function visibleFile(Request $request, string $id): array
    {
        // Explicit query, not route binding: bindings run before the `org` middleware sets the scope.
        $file = DocumentFile::query()->whereKey($id)->firstOrFail();

        return [$file, $this->visible($request)->withPrintTray()->whereKey($file->document_id)->firstOrFail()];
    }

    /**
     * @return Builder<Document>
     */
    private function visible(Request $request): Builder
    {
        return Document::query()->visibleTo($this->userId($request), Gate::allows(Permission::ViewAllDocuments->value));
    }

    /**
     * Only existing tags of the active organisation (Tag is org-scoped).
     *
     * @return array<string, list<mixed>>
     */
    private static function tagRules(): array
    {
        return ['tags' => ['array'], 'tags.*' => [Rule::in(Tag::query()->pluck('id')->all())]];
    }

    /**
     * The folder rows on top of a Belege window (window system, PO 2026-11-07): without a folder the
     * top folders with the Papierkorb in its place, inside a folder its subfolders. Counts include
     * everything below a folder and only receipts this person may see.
     *
     * @param  list<array{folder: Folder, depth: int}>  $folders
     * @return list<array{folder: Folder|null, count: int}>
     */
    private function folderRows(Request $request, array $folders, ?string $folder): array
    {
        $direct = Document::query()->visibleTo($this->userId($request), Gate::allows(Permission::ViewAllDocuments->value))
            ->whereNotNull('folder_id')->selectRaw('folder_id, count(*) as total')->groupBy('folder_id')->pluck('total', 'folder_id');
        // Like an explorer: a folder row counts only the documents directly in it (point 12).
        $total = static fn (string $id): int => (int) ($direct[$id] ?? 0);

        $out = [];
        if ($folder !== null) {
            foreach ($folders as $row) {
                if ($row['folder']->parent_id === $folder) {
                    $out[] = ['folder' => $row['folder'], 'count' => $total($row['folder']->id)];
                }
            }

            return $out;
        }

        $org = app(CurrentOrg::class);
        foreach (Folder::menu($org->isSet() ? $org->organization() : null) as $row) {
            if ($row['depth'] !== 0) {
                continue;
            }
            if ($row['folder'] === null) {
                // The Papierkorb, in its place among the top folders, for people who may open it.
                if (Gate::allows(Permission::TrashDocuments->value)) {
                    $out[] = ['folder' => null, 'count' => 0];
                }

                continue;
            }
            $out[] = ['folder' => $row['folder'], 'count' => $total($row['folder']->id)];
        }

        return $out;
    }

    /**
     * Only an existing folder of the active organisation (Folder is org-scoped); empty means "Belege".
     *
     * @return array<string, mixed>
     */
    private static function folderRule(): array
    {
        return ['folder' => ['nullable', 'string', Rule::in(Folder::query()->pluck('id')->all())]];
    }

    private function userId(Request $request): string
    {
        return $this->user($request)->id;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
