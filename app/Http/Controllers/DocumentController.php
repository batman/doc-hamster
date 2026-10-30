<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use finfo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function index(Request $request): View
    {
        $documents = Document::query()
            ->visibleTo($this->userId($request), Gate::allows(Permission::ViewAllDocuments->value))
            ->with('files')
            ->latest()
            ->limit(200)
            ->get();

        return view('documents.index', ['documents' => $documents]);
    }

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:25600']]);

        $upload = $request->file('file');
        // From the file's bytes – client name and client MIME type can say anything.
        $mime = $upload instanceof UploadedFile ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()) : '';
        $extension = self::EXTENSIONS[$mime] ?? null;

        if (! $upload instanceof UploadedFile || $extension === null) {
            return back()->withErrors(['file' => __('documents.wrong_type')]);
        }

        $userId = $this->userId($request);
        $document = DB::transaction(static function () use ($upload, $extension, $mime, $org, $userId): Document {
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

            return $document;
        });

        $audit->record('document.uploaded', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());

        return redirect('/')->with('status', __('documents.saved'));
    }

    public function file(Request $request, string $id): Response
    {
        // Explicit query, not route binding: bindings run before the `org` middleware sets the scope.
        $file = DocumentFile::query()->whereKey($id)->firstOrFail();

        Document::query()
            ->visibleTo($this->userId($request), Gate::allows(Permission::ViewAllDocuments->value))
            ->whereKey($file->document_id)
            ->firstOrFail();

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $file->mime]);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user->id;
    }
}
