<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Tag;
use App\Domain\Documents\Thumbnail;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * MVP receipts: list, upload (photo or PDF), protected file delivery.
 * Files are checked by their content and stay outside the web root (PLAN §11.2).
 */
final class DocumentController
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
    ];

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
        $complete = $selected === [] && ($view === 'calendar' || $documents->count() <= self::LIMIT);

        return [
            'open' => null,
            'documents' => $view === 'calendar' ? $documents : $documents->take(self::LIMIT),
            'tags' => $tags,
            'selected' => $selected,
            'view' => $view,
            'views' => self::VIEWS,
            'month' => $month,
            'complete' => $complete,
            'trashed' => Gate::allows(Permission::TrashDocuments->value) ? $this->visible($request)->onlyTrashed()->count() : 0,
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
        ]);

        $many = $request->file('files');
        $checked = [];
        foreach (is_array($many) ? $many : [$request->file('file')] as $upload) {
            // From the file's bytes – client name and client MIME type can say anything.
            $mime = $upload instanceof UploadedFile ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()) : '';
            $extension = self::EXTENSIONS[$mime] ?? null;

            if (! $upload instanceof UploadedFile || $extension === null) {
                return back()->withErrors(['file' => __('documents.wrong_type')]);
            }
            $checked[] = [$upload, $mime, $extension];
        }

        $userId = $this->userId($request);
        foreach ($checked as [$upload, $mime, $extension]) {
            $document = DB::transaction(static function () use ($upload, $extension, $mime, $org, $userId, $data): Document {
                $document = Document::query()->create([
                    'uploaded_by' => $userId,
                    'title' => Str::limit($upload->getClientOriginalName(), 250, ''),
                ]);
                $path = $upload->storeAs($org->id().'/'.now()->format('Y/m'), Str::lower((string) Str::ulid()).'.'.$extension, 'local');

                DocumentFile::query()->create([
                    'document_id' => $document->id,
                    'path' => (string) $path,
                    'mime' => $mime,
                    'size' => (int) $upload->getSize(),
                    'sha256' => (string) hash_file('sha256', $upload->getRealPath()),
                ]);
                $document->tags()->sync($data['tags'] ?? []);

                return $document;
            });
            // Once, at upload: the list shows only this small copy (design point 14).
            $file = $document->files()->firstOrFail();
            $file->update(['thumb_path' => Thumbnail::make($file)]);

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
        ]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();

        $document->update(['note' => $data['note'] ?? null, ...(isset($data['title']) ? ['title' => $data['title']] : [])]);
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

    /** The trash (#30 "Belege löschen"): receipts deleted by mistake come back from here. */
    public function trash(Request $request): View
    {
        return view('documents.trash', ['documents' => $this->visible($request)->onlyTrashed()->with('files')->latest('deleted_at')->limit(200)->get()]);
    }

    public function restore(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $document = $this->visible($request)->onlyTrashed()->whereKey($id)->firstOrFail();
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

        // A renamed receipt keeps its file type in the download name.
        $extension = pathinfo($file->path, PATHINFO_EXTENSION);
        $name = str_ends_with(Str::lower($document->title), '.'.$extension) ? $document->title : $document->title.'.'.$extension;

        return Storage::disk('local')->download($file->path, $name, ['Content-Type' => $file->mime]);
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

        return [$file, $this->visible($request)->whereKey($file->document_id)->firstOrFail()];
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
