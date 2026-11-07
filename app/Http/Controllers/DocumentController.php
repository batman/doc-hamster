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
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

        // One folder, with everything below it (issue #77).
        $folders = Folder::flat();
        $wantedFolder = $request->query('folder');
        $folder = is_string($wantedFolder) && collect($folders)->contains(static fn (array $row): bool => $row['folder']->id === $wantedFolder) ? $wantedFolder : null;
        if ($folder !== null) {
            $query->whereIn('folder_id', Folder::branchIds($folder));
        }

        $saved = $this->user($request)->receipt_view;
        $view = $saved !== null && isset(self::VIEWS[$saved]) ? $saved : 'details';

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
        $complete = $selected === [] && $folder === null && ($view === 'calendar' || $documents->count() <= self::LIMIT);

        return [
            'open' => null,
            'documents' => $view === 'calendar' ? $documents : $documents->take(self::LIMIT),
            'tags' => $tags,
            'selected' => $selected,
            'folders' => $folders,
            'folder' => $folder,
            'view' => $view,
            'views' => self::VIEWS,
            'month' => $month,
            'complete' => $complete,
            'trashed' => Gate::allows(Permission::TrashDocuments->value) ? $this->visible($request)->withPrintTray()->onlyTrashed()->count() : 0,
        ];
    }

    /** The chosen view is kept per person (point 11). */
    public function setView(Request $request): RedirectResponse
    {
        $data = $request->validate(['view' => ['required', Rule::in(array_keys(self::VIEWS))]]);

        $this->user($request)->forceFill(['receipt_view' => $data['view']])->save();

        return back();
    }

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
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
                return back()->withErrors(['file' => __('documents.wrong_type')]);
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
        $document->update([
            'note' => $data['note'] ?? null,
            ...(isset($data['title']) ? ['title' => $data['title']] : []),
            ...($request->has('folder') ? ['folder_id' => $data['folder'] ?? Folder::defaultIdFor($org->id())] : []),
        ]);
        $document->tags()->sync($data['tags'] ?? []);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return back()->with('status', __('documents.changed'));
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
        return view('documents.trash', ['documents' => $this->visible($request)->withPrintTray()->onlyTrashed()->with('files')->latest('deleted_at')->limit(200)->get()]);
    }

    public function restore(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $document = $this->visible($request)->withPrintTray()->onlyTrashed()->whereKey($id)->firstOrFail();
        $document->restore();
        $audit->record('document.restored', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return redirect()->route('documents.trash')->with('status', __('documents.restored'));
    }

    public function file(Request $request, string $id): Response
    {
        [$file, $document] = $this->visibleFile($request, $id);
        $document->forceFill(['last_opened_at' => now()])->saveQuietly();

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $file->mime]);
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
