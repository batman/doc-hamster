<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Backup\Zip\StreamZip;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Http\Controllers\Concerns\ScopesDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Point 237: a folder with all its subfolders, or the picked documents, as one ZIP – streamed straight to the browser,
 * nothing stored on the server (A413). Only what the person may see (A412; role restrictions apply through the folder
 * and document scopes), never the Papierkorb. Inside, the folder tree is kept; files are named after the document title,
 * a repeated name gets " (2)" (A411).
 */
final class DocumentZipController
{
    use ScopesDocuments;

    public function folder(Request $request, string $id, AuditLog $audit, CurrentOrg $org): Response
    {
        $root = Folder::query()->whereKey($id)->firstOrFail();
        $paths = $this->paths($root);
        $documents = $this->visible($request)->whereIn('folder_id', array_keys($paths))->with('files')->get();
        $audit->record('documents.zip_downloaded', orgId: $org->id(), userId: $this->userId($request), subject: $root, meta: ['documents' => $documents->count()], ip: $request->ip());

        return $this->stream($root->name, $documents, static fn (Document $d): string => $paths[$d->folder_id] ?? '');
    }

    public function picked(Request $request, AuditLog $audit, CurrentOrg $org): Response
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:5000'], 'ids.*' => ['string', 'max:26']]);
        $documents = $this->visible($request)->whereKey($data['ids'])->with('files')->get();
        abort_if($documents->isEmpty(), 404);
        $audit->record('documents.zip_downloaded', orgId: $org->id(), userId: $this->userId($request), meta: ['documents' => $documents->count()], ip: $request->ip());

        return $this->stream(__('documents.zip.picked_name'), $documents, static fn (): string => '');
    }

    /**
     * Every folder of the branch with its path inside the ZIP ("Root/Sub/Subsub/").
     *
     * @return array<string, string>
     */
    private function paths(Folder $root): array
    {
        $byId = [];
        foreach (Folder::flat() as $row) {
            $byId[$row['folder']->id] = $row['folder'];
        }
        $paths = [];
        foreach (Folder::branchIds($root->id) as $id) {
            $parts = [];
            for ($f = $byId[$id] ?? null; $f !== null; $f = $f->id === $root->id ? null : ($byId[$f->parent_id ?? ''] ?? null)) {
                array_unshift($parts, self::safe($f->name));
            }
            $paths[$id] = implode('/', $parts).'/';
        }

        return $paths;
    }

    /**
     * @param  iterable<Document>  $documents
     * @param  callable(Document): string  $dirOf
     */
    private function stream(string $name, iterable $documents, callable $dirOf): StreamedResponse
    {
        $entries = [];
        $taken = [];
        foreach ($documents as $document) {
            $files = $document->files->values();
            foreach ($files as $n => $file) {
                $title = $files->count() > 1 ? $document->title.' – '.($n + 1) : $document->title;
                $entries[] = [self::unique($dirOf($document).self::safe($file->downloadName($title)), $taken), $file->path];
            }
        }
        abort_if(count($entries) > StreamZip::MAX_ENTRIES, 413);

        return response()->streamDownload(static function () use ($entries): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            $zip = new StreamZip($out);
            $disk = Storage::disk('local');
            foreach ($entries as [$entry, $path]) {
                if ($disk->exists($path)) {
                    $zip->addFile($entry, $disk->path($path));
                }
            }
            $zip->finish();
        }, self::safe($name).'.zip', ['Content-Type' => 'application/zip', 'X-Accel-Buffering' => 'no']);
    }

    /**
     * @param  array<string, true>  $taken
     */
    private static function unique(string $entry, array &$taken): string
    {
        $dot = strrpos($entry, '.');
        $slash = strrpos($entry, '/');
        [$stem, $ext] = $dot !== false && ($slash === false || $dot > $slash) ? [substr($entry, 0, $dot), substr($entry, $dot)] : [$entry, ''];
        $candidate = $entry;
        for ($i = 2; isset($taken[mb_strtolower($candidate)]); $i++) {
            $candidate = $stem.' ('.$i.')'.$ext;
        }
        $taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /** No slashes, control characters or leading dots in a name inside the ZIP. */
    private static function safe(string $name): string
    {
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F\x7F]+/u', '-', $name));
        $name = ltrim($name, '.');

        return $name === '' ? '_' : $name;
    }
}
