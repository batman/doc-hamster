<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\AssistantAccess;
use App\Domain\Assistant\Proposal;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Http\Badges;
use App\Http\Middleware\AuthenticateAccessKey;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Point 45 (A108–A111): an assistant uploads a receipt or an important e-mail (as PDF) – right "Dokumente hochladen".
 * The document goes straight into the Posteingang (source "Assistent") and is read like every upload. What the person
 * decides stays a proposal: a named folder becomes "In Ordner verschieben", a date a correction of the Rechnungsdatum.
 * The same file twice is never stored twice: it is there already (the answer names it), or it lies in the Papierkorb –
 * then a proposal "Aus dem Papierkorb zurückholen" asks the person.
 */
final class McpUpload
{
    /** Bytes per file (the file comes Base64 in the JSON request, a third larger). */
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** What may come in: PDF and the two picture types (MIME from the bytes, never from the caller). */
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * @param  array<string, mixed>  $reason  the reason field's schema
     * @param  array<string, mixed>  $sure  the sure field's schema
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function definitions(array $reason, array $sure): array
    {
        return [
            'upload_document' => ['Upload a document', 'Uploads a receipt, an invoice or an important e-mail (save it as PDF first) into docHamster\'s Posteingang (source "Assistent"); '
                .'the text recognition reads it like every upload. At most 20 MB; PDF, JPEG or PNG. The same file is never stored twice: the answer names the document '
                .'that is there already – or, when it lies in the Papierkorb, files a proposal to bring it back (the person decides). folder and date are proposals '
                .'too (move into the folder, correct the invoice date); sure applies to them.', ['type' => 'object', 'properties' => [
                    'filename' => ['type' => 'string', 'description' => 'The file name, e.g. "Rechnung-2026-11.pdf".'],
                    'content' => ['type' => 'string', 'description' => 'The file itself, Base64.'],
                    'mime_type' => ['type' => 'string', 'enum' => array_keys(self::TYPES), 'description' => 'application/pdf, image/jpeg or image/png.'],
                    'title' => ['type' => 'string', 'description' => 'Title in docHamster (sender, what it is, date); default: the file name.'],
                    'date' => ['type' => 'string', 'description' => 'The document\'s date (YYYY-MM-DD) – filed as a proposal for the Rechnungsdatum.'],
                    'folder' => ['type' => 'string', 'description' => 'Folder id (list_folders) or exact folder name – filed as a proposal to move it there.'],
                    'reason' => [...$reason, 'description' => 'Where it comes from, e.g. "E-Mail von Muster GmbH, Betreff Rechnung 4711, 03.11.2026" – shown on the document and in the Logbuch.'],
                    'sure' => $sure,
                ], 'required' => ['filename', 'content', 'mime_type', 'reason'], 'additionalProperties' => false], AssistantAccess::UPLOAD],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  callable(string): string  $text
     * @return array{bool, string}
     */
    public static function upload(array $args, callable $text, Request $outer, CurrentOrg $org): array
    {
        $raw = is_string($args['content'] ?? null) ? preg_replace('/\s+/', '', $args['content']) ?? '' : '';
        if (strlen($raw) > (int) ceil(self::MAX_BYTES / 3) * 4 + 4) {
            return [true, (string) __('assistant.upload.too_big', ['mb' => self::MAX_BYTES / 1048576])];
        }
        $bytes = base64_decode((string) preg_replace('/^data:[^,]*,/', '', $raw), true);
        if ($bytes === false || $bytes === '') {
            return [true, (string) __('assistant.upload.not_base64')];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return [true, (string) __('assistant.upload.too_big', ['mb' => self::MAX_BYTES / 1048576])];
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! isset(self::TYPES[$mime])) {
            return [true, (string) __('assistant.upload.wrong_type', ['type' => $mime])];
        }
        $userId = (string) $org->membership()->user_id;
        $key = $outer->attributes->get(AuthenticateAccessKey::ATTRIBUTE);
        $assistant = is_object($key) && is_string($key->name ?? null) ? $key->name : null;
        $sure = match ($text('sure')) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
        $reason = mb_substr($text('reason'), 0, 1000);

        // A110: the same file is there already – or in the Papierkorb, where only the person brings it back.
        $known = DocumentIntake::known(hash('sha256', $bytes));
        if ($known !== null) {
            if (! $known->trashed()) {
                $visible = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value))->whereKey($known->id)->exists();

                return [false, self::json(['document_id' => $visible ? $known->id : null, 'new' => false,
                    'message' => 'This file is in docHamster already'.($visible ? ' ("'.$known->title.'")' : '').' – nothing stored twice.'])];
            }
            [$proposal, $new] = Proposal::file(Proposal::RESTORE, ['document_id' => $known->id], $reason, $assistant, $sure);

            return [false, self::json(['document_id' => $known->id, 'proposal_id' => $proposal->id, 'new' => false,
                'message' => ($new ? 'This file lies in the Papierkorb – filed a proposal to bring it back' : 'This file lies in the Papierkorb – the proposal to bring it back is waiting')
                    .'; the person decides under Vorschläge.'])];
        }

        $filename = Str::limit(basename(str_replace('\\', '/', $text('filename'))), 200, '');
        $title = $text('title') !== '' ? mb_substr($text('title'), 0, 250) : (pathinfo($filename, PATHINFO_FILENAME) ?: 'Dokument');
        $tmp = tempnam(sys_get_temp_dir(), 'dh-mcp-');
        file_put_contents($tmp, $bytes);
        try {
            $document = DocumentIntake::store(new UploadedFile($tmp, $filename !== '' ? $filename : 'dokument.'.self::TYPES[$mime], $mime, null, true),
                $mime, self::TYPES[$mime] === 'jpg' ? 'jpg' : self::TYPES[$mime], $org->id(), $userId, $title, source: 'assistant');
        } finally {
            @unlink($tmp);
        }
        $document->forceFill(['note' => (string) __('assistant.upload.note', ['reason' => $reason])])->saveQuietly();
        app(AuditLog::class)->record('document.uploaded_assistant', orgId: $org->id(), userId: $userId, subject: $document,
            meta: ['via' => 'assistant', 'source' => 'assistant', 'file' => $filename, 'connection' => $assistant], ip: $outer->ip());
        Badges::forget();

        // A109: what the person decides – the folder and the date – waits as proposals.
        $proposals = [];
        $folder = self::folder($text('folder'));
        if ($text('folder') !== '' && $folder !== null) {
            $proposals[] = Proposal::file(Proposal::MOVE, ['document_id' => $document->id, 'folder_id' => $folder], $reason, $assistant, $sure)[0]->id;
        }
        $date = $text('date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $proposals[] = Proposal::file(Proposal::CORRECT, ['document_id' => $document->id, 'property' => 'invoice_date', 'value' => $date], $reason, $assistant, $sure)[0]->id;
        }

        return [false, self::json(array_filter(['document_id' => $document->id, 'new' => true, 'proposal_ids' => $proposals,
            'message' => 'Stored in the Posteingang (source "Assistent"); the text recognition reads it now.'
                .($text('folder') !== '' && $folder === null ? ' The folder "'.$text('folder').'" was not found – use list_folders.' : ''),
        ], static fn (mixed $v): bool => $v !== []))];
    }

    /** A folder the person may file into, by id or by its exact name (only when that name is unique). */
    private static function folder(string $given): ?string
    {
        if ($given === '') {
            return null;
        }
        $targets = Folder::targetIds();
        if (in_array($given, $targets, true)) {
            return $given;
        }
        $named = Folder::query()->whereIn('id', $targets)->whereRaw('lower(name) = ?', [mb_strtolower($given)])->pluck('id')->all();

        return count($named) === 1 ? (string) $named[0] : null;
    }

    /** @param  array<mixed>  $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
