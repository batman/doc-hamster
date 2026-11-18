<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\AssistantAccess;
use App\Domain\Assistant\FolderProposals;
use App\Domain\Assistant\Proposal;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Http\Controllers\Api\BankApiController;
use App\Http\Middleware\AuthenticateAccessKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * More assistant tools (split from McpTools): read_log (point 32, A85–A87: the Logbuch, read only, never entries about
 * documents in private folders) and the folder proposals (point 33, A88–A90: create with documents, rename, move,
 * delete – checked by FolderProposals with the same rules as by hand).
 */
final class McpMore
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $id
     * @param  array<string, mixed>  $page
     * @param  callable(array<string, mixed>, list<string>|null=): array<string, mixed>  $propose
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function definitions(array $id, array $page, callable $propose): array
    {
        $folder = [...$id, 'description' => 'The folder (from list_folders).'];
        $parent = [...$id, 'description' => 'The folder it goes into; leave out for the top level.'];

        return [
            'read_log' => ['Read the log', 'The Logbuch, newest first, 50 per page: what worked, what did not and where errors are – uploads (with their protocol in body), text recognition, imports, updates. Read only.', ['type' => 'object', 'properties' => [
                'book' => ['type' => 'string', 'enum' => ['general', 'ocr', 'updates'], 'description' => 'general = signing in, uploading, changes, errors; ocr = text recognition; updates.'],
                'level' => ['type' => 'string', 'enum' => ['error', 'warning', 'event'], 'description' => 'error = critical, warning = hint, event = normal.'],
                'from' => ['type' => 'string', 'description' => 'First day (YYYY-MM-DD).'], 'to' => ['type' => 'string', 'description' => 'Last day (YYYY-MM-DD).'],
                'search' => ['type' => 'string', 'description' => 'Text in the entry.'], 'page' => $page,
            ], 'additionalProperties' => false], AssistantAccess::LOG],
            'propose_folder_create' => ['Propose a new folder', 'Proposes creating a folder (name unique on its level, at most '.Folder::MAX_DEPTH.' levels deep) – optionally with the documents that should go into it: one click creates it and moves them in.', $propose([
                'name' => ['type' => 'string', 'description' => 'The folder name (1–100 characters, no slash).'],
                'parent_id' => $parent,
                'document_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 500, 'description' => 'Documents to move into the new folder (optional).'],
            ], ['name']), AssistantAccess::PROPOSE_FOLDERS],
            'propose_folder_rename' => ['Propose renaming a folder', 'Proposes a clearer name for a folder.', $propose(['folder_id' => $folder, 'name' => ['type' => 'string', 'description' => 'The new name.']]), AssistantAccess::PROPOSE_FOLDERS],
            'propose_folder_move' => ['Propose moving a folder', 'Proposes moving a folder (with everything in it) into another folder or to the top level. "Dokumente allgemein" never moves.', $propose(['folder_id' => $folder, 'parent_id' => $parent], ['folder_id']), AssistantAccess::PROPOSE_FOLDERS],
            'propose_folder_delete' => ['Propose deleting a folder', 'Proposes deleting a folder; what is inside goes into the Papierkorb (never deleted for good). The person sees what is inside first. "Dokumente allgemein" is never deleted.', $propose(['folder_id' => $folder]), AssistantAccess::PROPOSE_FOLDERS],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  callable(string): string  $text
     * @return array{bool, string}
     */
    public static function proposeFolder(string $name, array $args, callable $text): array
    {
        $kind = match ($name) {
            'propose_folder_create' => Proposal::FOLDER_CREATE,
            'propose_folder_rename' => Proposal::FOLDER_RENAME,
            'propose_folder_move' => Proposal::FOLDER_MOVE,
            default => Proposal::FOLDER_DELETE,
        };
        $payload = match ($kind) {
            Proposal::FOLDER_CREATE => array_filter(['name' => mb_substr($text('name'), 0, 100), 'parent_id' => $text('parent_id') ?: null,
                'document_ids' => implode(',', array_unique(array_filter(array_slice((array) ($args['document_ids'] ?? []), 0, 500), static fn (mixed $id): bool => is_string($id) && preg_match('/^[0-9a-z]{26}$/i', $id) === 1))) ?: null], static fn (mixed $v): bool => $v !== null),
            Proposal::FOLDER_RENAME => ['folder_id' => $text('folder_id'), 'name' => mb_substr($text('name'), 0, 100)],
            Proposal::FOLDER_MOVE => array_filter(['folder_id' => $text('folder_id'), 'parent_id' => $text('parent_id') ?: null], static fn (mixed $v): bool => $v !== null),
            default => ['folder_id' => $text('folder_id')],
        };
        $problem = FolderProposals::problem($kind, $payload);
        $ids = FolderProposals::ids($payload);
        if ($problem === null && $ids !== []) {
            $visible = BankApiController::visible(app(CurrentOrg::class))->whereIn('id', $ids)->count();
            $problem = $visible !== count($ids) ? 'document' : null;
        }
        if ($problem !== null) {
            return [true, (string) __('assistant.propose_unknown.'.$problem)];
        }
        $key = request()->attributes->get(AuthenticateAccessKey::ATTRIBUTE);
        $sure = match ($text('sure')) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
        [$proposal, $new] = Proposal::file($kind, $payload, $text('reason'), $key?->name, $sure);

        return [false, (string) json_encode(['proposal_id' => $proposal->id, 'new' => $new,
            'message' => $new ? 'Filed – the person decides under Vorschläge.' : 'Already filed – it is waiting under Vorschläge, nothing more to do.'], JSON_UNESCAPED_UNICODE)];
    }

    /**
     * read_log: the logbooks "general" and "updates" (log_entries) and "ocr" (ocr_log), newest first – never an entry
     * about a document in a private folder (A87).
     *
     * @param  array<string, mixed>  $args
     * @param  callable(string): string  $text
     * @return array{bool, string}
     */
    public static function log(array $args, callable $text, CurrentOrg $org): array
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $take = $page * self::PER_PAGE + 1;
        $book = $text('book');
        $level = $text('level');
        $like = '%'.addcslashes($text('search'), '\\%_').'%';
        $private = Document::query()->withTrashed()->whereIn('folder_id', Folder::privateBranch())->select('id');
        $period = static function ($q) use ($text): void {
            if ($text('from') !== '') {
                $q->where('created_at', '>=', Carbon::parse($text('from'))->startOfDay());
            }
            if ($text('to') !== '') {
                $q->where('created_at', '<=', Carbon::parse($text('to'))->endOfDay());
            }
        };
        $rows = collect();
        if ($book !== 'ocr') {
            $rows = $rows->merge(DB::table('log_entries')->where(static fn ($q) => $q->where('org_id', $org->id())->orWhereNull('org_id'))
                ->when($book !== '', static fn ($q) => $q->where('book', $book))->when($level !== '', static fn ($q) => $q->where('level', $level))
                ->when($text('search') !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('message', 'like', $like)->orWhere('body', 'like', $like)))
                ->where(static fn ($q) => $q->whereNull('document_id')->orWhereNotIn('document_id', $private))
                ->tap($period)->latest('created_at')->limit($take)->get()
                ->map(static fn (object $r): array => ['time' => (string) $r->created_at, 'book' => $r->book, 'level' => $r->level, 'event' => $r->event,
                    'text' => $r->message, 'body' => $r->body, 'document_id' => $r->document_id]));
        }
        if ($book === '' || $book === 'ocr') {
            $rows = $rows->merge(OcrLog::query()->where('org_id', $org->id())
                ->when($level === 'error', static fn ($q) => $q->whereIn('event', OcrLog::ERRORS))
                ->when($level === 'event', static fn ($q) => $q->whereNotIn('event', OcrLog::ERRORS))
                ->when($text('search') !== '', static fn ($q) => $q->where('message', 'like', $like))
                ->where(static fn ($q) => $q->whereNull('document_id')->orWhereNotIn('document_id', $private))
                ->tap($period)->latest('created_at')->limit($level === 'warning' ? 0 : $take)->get()
                ->map(static fn (OcrLog $r): array => ['time' => (string) $r->created_at, 'book' => 'ocr', 'level' => in_array($r->event, OcrLog::ERRORS, true) ? 'error' : 'event',
                    'event' => $r->event, 'text' => (string) $r->message, 'body' => null, 'document_id' => $r->document_id]));
        }
        $sorted = $rows->sortByDesc('time')->values();
        $slice = $sorted->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()->all();

        return [false, (string) json_encode(['data' => $slice, 'next_page' => $sorted->count() > $page * self::PER_PAGE ? $page + 1 : null], JSON_UNESCAPED_UNICODE)];
    }
}
