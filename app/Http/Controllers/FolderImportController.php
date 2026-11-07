<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Folder import (PO, 2026-11-07): a folder picked on the computer becomes a
 * new folder with the same name; its subfolders become subfolders and its
 * files receipts. The browser sends the files one by one, so a big folder
 * never hits the upload limits of the server.
 */
final class FolderImportController
{
    /** Starts an import: a new folder with the picked folder's name ("Name (2)" if taken). */
    public function start(Request $request, CurrentOrg $org, AuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'string'],
        ]);
        $parent = isset($data['parent_id']) ? Folder::query()->whereKey($data['parent_id'])->first() : null;
        if (isset($data['parent_id']) && ($parent === null || $parent->depth() >= Folder::MAX_DEPTH)) {
            return response()->json(['message' => __('folders.parent_invalid')], 422);
        }

        $folder = Folder::query()->create([
            'org_id' => $org->id(),
            'parent_id' => $parent?->id,
            'name' => self::freeName(self::clean($data['name']), $parent?->id),
            'bank_matching' => true,
        ]);
        $audit->record('folder.imported', orgId: $org->id(), userId: $org->membership()->user_id, subject: $folder, meta: ['name' => $folder->name], ip: $request->ip());

        return response()->json(['id' => $folder->id, 'name' => $folder->name, 'url' => route('documents.index', ['folder' => $folder->id])], 201);
    }

    /**
     * One file of the import. `path` is where it lay below the picked folder,
     * e.g. "2025/Tanken/beleg.pdf"; missing subfolders are made on the way.
     * Below the deepest allowed level, files go into the deepest folder.
     */
    public function file(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse
    {
        $root = Folder::query()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'file' => ['required', 'file', 'max:25600'],
            'path' => ['required', 'string', 'max:1000'],
        ]);

        $upload = $request->file('file');
        $type = $upload instanceof UploadedFile ? DocumentIntake::detect($upload) : null;
        if (! $upload instanceof UploadedFile || $type === null) {
            return response()->json(['message' => __('documents.wrong_type')], 422);
        }

        $parts = array_filter(explode('/', str_replace('\\', '/', $data['path'])), static fn (string $part): bool => trim($part) !== '' && $part !== '.' && $part !== '..');
        $segments = array_values(array_map(self::clean(...), $parts));
        $fileName = array_pop($segments) ?? $upload->getClientOriginalName();

        $folder = $root;
        $depth = $root->depth();
        foreach ($segments as $segment) {
            if ($depth >= Folder::MAX_DEPTH) {
                break;
            }
            $folder = self::child($folder, $segment, $org->id());
            $depth++;
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        [$mime, $extension] = $type;
        $document = DocumentIntake::store($upload, $mime, $extension, $org->id(), $user->id, $fileName, folderId: $folder->id);
        $audit->record('document.uploaded', orgId: $org->id(), userId: $user->id, subject: $document, ip: $request->ip());

        return response()->json(['saved' => 1], 201);
    }

    /** The subfolder with this name, made if missing (names compared without case, like the folder form). */
    private static function child(Folder $parent, string $name, string $orgId): Folder
    {
        $existing = Folder::query()->where('parent_id', $parent->id)->get()
            ->first(static fn (Folder $folder): bool => mb_strtolower($folder->name) === mb_strtolower($name));

        return $existing ?? Folder::query()->create(['org_id' => $orgId, 'parent_id' => $parent->id, 'name' => $name, 'bank_matching' => $parent->bank_matching]);
    }

    /** A name the folder form would accept: no slashes, no control characters, at most 100 characters. */
    private static function clean(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', $name));

        return mb_substr($name === '' ? __('folders.imported') : $name, 0, 100);
    }

    /** "Name", or "Name (2)", "Name (3)" … if the level already has it. */
    private static function freeName(string $name, ?string $parentId): string
    {
        $taken = Folder::query()->where('parent_id', $parentId)->pluck('name')->map(static fn (string $n): string => mb_strtolower($n));
        $candidate = $name;
        for ($n = 2; $taken->contains(mb_strtolower($candidate)); $n++) {
            $suffix = ' ('.$n.')';
            $candidate = mb_substr($name, 0, 100 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }
}
