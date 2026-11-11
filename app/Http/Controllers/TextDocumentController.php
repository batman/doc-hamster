<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Documents\TextDocument;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Text documents written in the app (point 21): create one in a folder, edit it in its own window.
 * Formatted text is sanitized on the server (RichText); the search finds the plain text.
 */
final class TextDocumentController
{
    public function store(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:250'],
            'kind' => ['required', Rule::in(['plain', 'rich'])],
            'folder' => ['nullable', 'string', Rule::in(Folder::query()->pluck('id')->all())],
        ]);
        $userId = $this->user($request)->id;
        $document = TextDocument::create($org->id(), $userId, (string) $data['name'], $data['kind'] === 'rich', $data['folder'] ?? null);
        $audit->record('document.created', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());

        $url = route('documents.text', $document->id);

        return $request->expectsJson() ? new JsonResponse(['url' => $url]) : redirect($url);
    }

    public function edit(Request $request, string $id): View
    {
        [$document, $file] = $this->find($request, $id);

        return view('documents.text', [
            'document' => $document,
            'file' => $file,
            'rich' => TextDocument::isRich($file),
            'content' => TextDocument::display($file),
            'canEdit' => Gate::allows(Permission::EditDocuments->value),
        ]);
    }

    public function update(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate(['content' => ['nullable', 'string']]);
        $content = (string) $request->input('content', '');
        if (strlen($content) > TextDocument::MAX_BYTES) {
            return $request->expectsJson()
                ? new JsonResponse(['message' => __('documents.text.too_big')], 422)
                : back()->withErrors(['content' => __('documents.text.too_big')]);
        }
        [$document, $file] = $this->find($request, $id);
        TextDocument::save($document, $file, $content);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->user($request)->id, subject: $document, ip: $request->ip());

        return $request->expectsJson()
            ? new JsonResponse(['message' => __('documents.changed')])
            : redirect()->route('documents.text', $document->id)->with('status', __('documents.changed'));
    }

    /**
     * @return array{Document, DocumentFile}
     */
    private function find(Request $request, string $id): array
    {
        $document = Document::query()
            ->visibleTo($this->user($request)->id, Gate::allows(Permission::ViewAllDocuments->value))
            ->with('files')->whereKey($id)->firstOrFail();
        $file = $document->files->first();
        abort_unless($file instanceof DocumentFile && TextDocument::isText($file), 404);

        return [$document, $file];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
