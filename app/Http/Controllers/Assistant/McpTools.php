<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\AssistantAccess;
use App\Domain\Assistant\Proposal;
use App\Domain\Assistant\ProposalVisibility;
use App\Domain\Assistant\Straightener;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\Property;
use App\Http\Badges;
use App\Http\Controllers\Api\BankApiController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Middleware\AuthenticateAccessKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The tools an assistant sees at the MCP address (D-040). It changes nothing itself: it reads (right "read") and files
 * proposals (one right per kind) that the owner takes over or not under Vorschläge. Only the tools the owner's ticked
 * rights allow are listed. Reading is a call of the interface (api/v1) – the same controllers, rights, restriction and
 * organisation. Text only; nothing here deletes.
 */
final class McpTools
{
    /**
     * name => [title, description, input schema, needed right]
     *
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function definitions(): array
    {
        $id = ['type' => 'string', 'description' => 'The id (ULID) as docHamster gives it.'];
        $date = static fn (string $what): array => ['type' => 'string', 'description' => $what.' (YYYY-MM-DD).'];
        $page = ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of the list (see next_page in the answer).'];
        $reason = ['type' => 'string', 'description' => 'Why – one or two sentences the person reads on the tile (e.g. "Amount 54.00 EUR, date and sender match").'];
        $none = ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
        $one = static fn (string $what): array => ['type' => 'object', 'properties' => ['id' => [...$id, 'description' => $what]], 'required' => ['id'], 'additionalProperties' => false];
        // A51: how sure the assistant is – the person takes over all sure ones in one go and looks at the others.
        $sure = ['type' => 'boolean', 'description' => 'true = you are sure (amount, date and counterparty fit clearly); false = a guess the person should look at.'];
        $propose = static fn (array $fields, ?array $required = null): array => ['type' => 'object', 'properties' => [...$fields, 'reason' => $reason, 'sure' => $sure], 'required' => [...($required ?? array_keys($fields)), 'reason'], 'additionalProperties' => false];
        $read = AssistantAccess::READ;

        return [
            'whoami' => ['Who am I in docHamster', 'Whose connection this is, the organisation, and which rights the owner ticked.', $none, $read],
            'list_accounts' => ['Bank accounts', 'The bank accounts with their number of bookings per status (open = no document, suggested = docHamster suggests one, linked, none = no document needed).', $none, $read],
            'list_transactions' => ['Bookings', 'Bank bookings, newest first, 100 per page. Amounts: amount_minor in cents and amount as a decimal string; negative = money out.', ['type' => 'object', 'properties' => [
                'account_id' => [...$id, 'description' => 'Only this account.'],
                'status' => ['type' => 'string', 'enum' => ['open', 'suggested', 'linked', 'none'], 'description' => 'open = no document yet; suggested = docHamster suggests one; linked; none = no document needed.'],
                'flow' => ['type' => 'string', 'enum' => ['in', 'out'], 'description' => 'Only money in or only money out.'],
                'from' => $date('First booking day'), 'to' => $date('Last booking day'),
                'search' => ['type' => 'string', 'description' => 'Text in counterparty, purpose, reference or note.'], 'page' => $page,
            ], 'additionalProperties' => false], $read],
            'get_transaction' => ['One booking', 'One booking with its documents (state confirmed = linked, suggested = docHamster\'s own suggestion) and up to 10 candidate documents with a score (0–100) and the reasons.', $one('The booking.'), $read],
            'list_folders' => ['Folders', 'The document folders; bank_matching tells whether its documents may be linked to bookings.', $none, $read],
            'list_documents' => ['Documents', 'Documents the person may see, 50 per page, with what docHamster read from them (amount, date, sender, IBAN, invoice number), their size and the bookings they are linked to.', ['type' => 'object', 'properties' => [
                'free_only' => ['type' => 'boolean', 'description' => 'Only documents that may be linked and are not linked to any booking yet.'],
                'folder_id' => [...$id, 'description' => 'Only this folder.'],
                'from' => $date('First document date'), 'to' => $date('Last document date'),
                'search' => ['type' => 'string', 'description' => 'Text in the title, the recognised text or a property.'], 'page' => $page,
            ], 'additionalProperties' => false], $read],
            'get_document' => ['One document', 'One document with all its properties, its files (type, size) and its recognised text.', $one('The document.'), $read],
            'get_page_image' => ['Look at a page', 'One page of a document as a picture (JPEG, at most 1600 px): photos and scanned PDFs. Use it to check what the text recognition read and to measure a tilted receipt.', ['type' => 'object', 'properties' => [
                'document_id' => [...$id, 'description' => 'The document.'], 'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, 1 = first (default).'],
            ], 'required' => ['document_id'], 'additionalProperties' => false], AssistantAccess::IMAGES],
            'list_proposals' => ['Your open proposals', 'The proposals still waiting for the person, so nothing is proposed twice.', $none, $read],
            'withdraw_proposal' => ['Withdraw a proposal', 'Takes back one of your own proposals that is still waiting (a mistake, or a better one replaces it).', $one('The proposal.'), $read],
            'propose_link' => ['Propose a link', 'Proposes that this document belongs to this booking. The person takes it over or not.', $propose(['transaction_id' => [...$id, 'description' => 'The booking.'], 'document_id' => [...$id, 'description' => 'The document.']]), AssistantAccess::PROPOSE_LINK],
            'propose_unlink' => ['Propose unlinking', 'Proposes removing the link between this document and this booking because it does not fit (the document stays).', $propose(['transaction_id' => [...$id, 'description' => 'The booking.'], 'document_id' => [...$id, 'description' => 'The document.']]), AssistantAccess::PROPOSE_LINK],
            'propose_move' => ['Propose a folder', 'Proposes moving the document into this folder (tidying up).', $propose(['document_id' => [...$id, 'description' => 'The document.'], 'folder_id' => [...$id, 'description' => 'The target folder.']]), AssistantAccess::PROPOSE_MOVE],
            'propose_duplicate' => ['Propose a duplicate', 'Proposes that remove_id is a duplicate of keep_id (the same paper, also scanned or photographed twice): taken over, the links, confirmed values, tags, note and folder of remove_id go to keep_id, then remove_id goes into the Papierkorb. Keep the better picture (sharper, straight, complete). The person may swap which one stays.', $propose(['keep_id' => [...$id, 'description' => 'The document that stays.'], 'remove_id' => [...$id, 'description' => 'The duplicate.']]), AssistantAccess::PROPOSE_CLEANUP],
            'propose_delete' => ['Propose deleting', 'Proposes moving the document into the Papierkorb (never deleted for good).', $propose(['document_id' => [...$id, 'description' => 'The document.']]), AssistantAccess::PROPOSE_CLEANUP],
            'propose_shrink' => ['Propose shrinking', 'Proposes shrinking this too large scan or photo (also when the automatic shrinking is off).', $propose(['document_id' => [...$id, 'description' => 'The document.']]), AssistantAccess::PROPOSE_SHRINK],
            'propose_straighten' => ['Propose straightening', 'Proposes straightening and cutting out a photographed receipt (photos only). Measure on the page picture: angle = how many degrees the receipt is turned clockwise (negative = counter-clockwise), -45 to 45; cx, cy = the receipt\'s centre and w, h = its width and height along the receipt itself, all as parts (0–1) of the picture\'s width and height. Leave cx, cy, w, h out to only straighten. The person sees before and after and decides; the original is kept.', ['type' => 'object', 'properties' => [
                'document_id' => [...$id, 'description' => 'The document.'],
                'angle' => ['type' => 'number', 'minimum' => -45, 'maximum' => 45, 'description' => 'Clockwise tilt of the receipt in degrees.'],
                'cx' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'cy' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'w' => ['type' => 'number', 'minimum' => 0.05, 'maximum' => 1.5], 'h' => ['type' => 'number', 'minimum' => 0.05, 'maximum' => 1.5],
                'reason' => $reason,
            ], 'required' => ['document_id', 'angle', 'reason'], 'additionalProperties' => false], AssistantAccess::PROPOSE_STRAIGHTEN],
            'propose_correction' => ['Propose a correction', 'Proposes a corrected value for a property the text recognition read wrong (e.g. invoice_amount "64,00 EUR", invoice_date "2026-11-03", sender "Muster GmbH").', $propose([
                'document_id' => [...$id, 'description' => 'The document.'],
                'property' => ['type' => 'string', 'description' => 'The property key (invoice_amount, invoice_date, due_date, sender, iban, invoice_number) or its name.'],
                'value' => ['type' => 'string', 'description' => 'The correct value as written on the document.'],
            ]), AssistantAccess::PROPOSE_CORRECT],
            'propose_clear' => ['Propose clearing a value', 'Proposes removing a property value that does not belong there (e.g. a VAT number in the IBAN field, an amount that is not on the document).', $propose([
                'document_id' => [...$id, 'description' => 'The document.'],
                'property' => ['type' => 'string', 'description' => 'The property key (invoice_amount, invoice_date, due_date, sender, iban, invoice_number) or its name.'],
            ]), AssistantAccess::PROPOSE_CORRECT],
            'propose_title' => ['Propose a title', 'Proposes a clearer title for the document (sender, what it is, the date) – also when the title names a wrong category.', $propose([
                'document_id' => [...$id, 'description' => 'The document.'],
                'title' => ['type' => 'string', 'description' => 'The new title (at most 255 characters).'],
            ]), AssistantAccess::PROPOSE_CORRECT],
            'propose_setting' => ['Propose a setting', 'Proposes switching a setting on: optimize_on = "Dokumentgröße optimieren" shrinks every too large scan automatically.', $propose(['setting' => ['type' => 'string', 'enum' => Proposal::SETTINGS]]), AssistantAccess::PROPOSE_SETTINGS],
            // Points 32, 33: the Logbuch and the folder proposals (McpMore).
            ...McpMore::definitions($id, $page, $propose),
        ];
    }

    /** @return list<array<string, mixed>> the tools the ticked rights allow, as MCP lists them */
    public static function listed(CurrentOrg $org): array
    {
        $rights = AssistantAccess::rights($org->organization());
        $tools = [];
        foreach (self::definitions() as $name => [$title, $description, $schema, $right]) {
            if (in_array($right, $rights, true) && ($right === AssistantAccess::READ || in_array(AssistantAccess::READ, $rights, true))) {
                $tools[] = ['name' => $name, 'title' => $title, 'description' => $description, 'inputSchema' => $schema,
                    'annotations' => ['readOnlyHint' => in_array($right, [AssistantAccess::READ, AssistantAccess::IMAGES, AssistantAccess::LOG], true) && $name !== 'withdraw_proposal', 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
            }
        }

        return $tools;
    }

    /**
     * Runs a tool as the same person.
     *
     * @param  array<string, mixed>  $args
     * @return array{bool, string|list<array<string, string>>} failed?, the answer as text (JSON or the reason) or MCP content
     */
    public static function call(string $name, array $args, Request $outer, CurrentOrg $org): array
    {
        $definition = self::definitions()[$name] ?? null;
        if ($definition === null) {
            return [true, 'Unknown tool: '.$name];
        }
        $rights = AssistantAccess::rights($org->organization());
        if (! in_array($definition[3], $rights, true) || ! in_array(AssistantAccess::READ, $rights, true)) {
            return [true, (string) __('assistant.not_allowed')];
        }
        $text = static fn (string $key): string => match (true) {
            is_string($args[$key] ?? null) => trim($args[$key]),
            is_int($args[$key] ?? null), is_float($args[$key] ?? null) => (string) $args[$key],
            is_bool($args[$key] ?? null) => $args[$key] ? 'true' : 'false',
            default => '',
        };
        foreach ($definition[2]['required'] ?? [] as $required) {
            if ($text($required) === '') {
                return [true, 'Missing: '.$required];
            }
        }

        if (str_starts_with($name, 'propose_folder_')) {
            return McpMore::proposeFolder($name, $args, $text);
        }
        if (str_starts_with($name, 'propose_')) {
            return self::propose($name, $text, $org);
        }
        if ($name === 'read_log') {
            return McpMore::log($args, $text, $org);
        }

        return match ($name) {
            'whoami' => [false, self::json(['person' => $org->membership()->user?->display_name, 'organisation' => $org->organization()->name,
                'connection' => BankApiController::key($outer)->name, 'rights' => $rights,
                'note' => 'You change nothing yourself: every change is a proposal the person takes over or not.'])],
            'get_page_image' => self::look($text('document_id'), max(1, (int) ($args['page'] ?? 1)), $org),
            // A95: only proposals about what this person (and so the assistant) may see – never private folders.
            'list_proposals' => [false, self::json(['data' => ProposalVisibility::filter(Proposal::query()->where('status', Proposal::OPEN)->latest()->limit(500)->get())
                ->map(static fn (Proposal $p): array => ['id' => $p->id, 'kind' => $p->kind, 'payload' => $p->payload, 'reason' => $p->reason, 'sure' => $p->sure, 'from' => $p->assistant])->all()])],
            'withdraw_proposal' => self::withdraw($text('id'), $outer),
            default => self::read($name, $args, $text, $outer),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  callable(string): string  $text
     * @return array{bool, string}
     */
    private static function read(string $name, array $args, callable $text, Request $outer): array
    {
        [$class, $method, $params, $input] = match ($name) {
            'list_accounts' => [BankApiController::class, 'accounts', [], []],
            'list_transactions' => [BankApiController::class, 'transactions', [], ['account' => $args['account_id'] ?? null, 'status' => $args['status'] ?? null,
                'flow' => $args['flow'] ?? null, 'from' => $args['from'] ?? null, 'to' => $args['to'] ?? null, 'q' => $args['search'] ?? null, 'page' => $args['page'] ?? null]],
            'get_transaction' => [BankApiController::class, 'transaction', ['id' => $text('id')], []],
            'list_folders' => [DocumentApiController::class, 'folders', [], []],
            'list_documents' => [DocumentApiController::class, 'documents', [], ['free' => ($args['free_only'] ?? false) === true ? '1' : null, 'folder' => $args['folder_id'] ?? null,
                'from' => $args['from'] ?? null, 'to' => $args['to'] ?? null, 'q' => $args['search'] ?? null, 'page' => $args['page'] ?? null]],
            default => [DocumentApiController::class, 'document', ['id' => $text('id')], []],
        };
        $request = Request::create('/api/v1', 'GET', array_filter($input, static fn (mixed $v): bool => $v !== null && $v !== ''), [], [], ['REMOTE_ADDR' => $outer->ip()]);
        $request->attributes->set(AuthenticateAccessKey::ATTRIBUTE, $outer->attributes->get(AuthenticateAccessKey::ATTRIBUTE));
        $request->setUserResolver($outer->getUserResolver());
        $callable = [app($class), $method];
        if (! is_callable($callable)) {
            return [true, 'Unknown tool: '.$name];
        }
        try {
            $response = app()->call($callable, ['request' => $request, ...$params]);
        } catch (ValidationException $e) {
            return [true, implode(' ', array_map(static fn (array $m): string => implode(' ', $m), $e->errors()))];
        } catch (HttpExceptionInterface $e) {
            return [true, $e->getMessage() !== '' ? $e->getMessage() : 'docHamster answered '.$e->getStatusCode().'.'];
        }

        return [false, $response instanceof JsonResponse ? self::json($response->getData(true)) : ''];
    }

    /**
     * Files a proposal after checking that what it names exists for this person.
     *
     * @param  callable(string): string  $text
     * @return array{bool, string}
     */
    private static function propose(string $name, callable $text, CurrentOrg $org): array
    {
        $visible = BankApiController::visible($org);
        $document = static fn (string $key): bool => (clone $visible)->whereKey($text($key))->exists();
        [$kind, $payload, $problem] = match ($name) {
            'propose_link' => [Proposal::LINK, ['transaction_id' => $text('transaction_id'), 'document_id' => $text('document_id')],
                ! Transaction::query()->whereKey($text('transaction_id'))->exists() ? 'booking' : (! $document('document_id') ? 'document' : null)],
            'propose_move' => [Proposal::MOVE, ['document_id' => $text('document_id'), 'folder_id' => $text('folder_id')],
                ! $document('document_id') ? 'document' : (! in_array($text('folder_id'), Folder::targetIds(), true) ? 'folder' : null)],
            'propose_duplicate' => [Proposal::DUPLICATE, ['keep_id' => $text('keep_id'), 'remove_id' => $text('remove_id')],
                ! $document('keep_id') || ! $document('remove_id') || $text('keep_id') === $text('remove_id') ? 'document' : null],
            'propose_delete' => [Proposal::DELETE, ['document_id' => $text('document_id')], ! $document('document_id') ? 'document' : null],
            'propose_shrink' => [Proposal::SHRINK, ['document_id' => $text('document_id')], ! $document('document_id') ? 'document' : null],
            'propose_straighten' => [Proposal::STRAIGHTEN, array_filter(['document_id' => $text('document_id'), 'angle' => self::number($text('angle'), -45, 45),
                'cx' => self::number($text('cx'), 0, 1), 'cy' => self::number($text('cy'), 0, 1), 'w' => self::number($text('w'), 0.05, 1.5), 'h' => self::number($text('h'), 0.05, 1.5)], static fn (string $v): bool => $v !== ''),
                ! $document('document_id') ? 'document' : (! self::hasPhoto($text('document_id')) ? 'photo' : null)],
            'propose_unlink' => [Proposal::UNLINK, ['transaction_id' => $text('transaction_id'), 'document_id' => $text('document_id')],
                ! $document('document_id') ? 'document' : (! TransactionMatch::query()->where('transaction_id', $text('transaction_id'))->where('document_id', $text('document_id'))->exists() ? 'link' : null)],
            'propose_clear' => [Proposal::CORRECT, ['document_id' => $text('document_id'), 'property' => $text('property'), 'value' => ''],
                ! $document('document_id') ? 'document' : (! Property::query()->where('key', $text('property'))->orWhere('name', $text('property'))->exists() ? 'property' : null)],
            'propose_title' => [Proposal::TITLE, ['document_id' => $text('document_id'), 'title' => mb_substr($text('title'), 0, 255)], ! $document('document_id') ? 'document' : null],
            'propose_correction' => [Proposal::CORRECT, ['document_id' => $text('document_id'), 'property' => $text('property'), 'value' => $text('value')],
                ! $document('document_id') ? 'document' : (! Property::query()->where('key', $text('property'))->orWhere('name', $text('property'))->exists() ? 'property' : null)],
            default => [Proposal::SETTING, ['setting' => $text('setting')], ! in_array($text('setting'), Proposal::SETTINGS, true) ? 'setting' : null],
        };
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

        // A53: a repeated call (after a timeout) is no error – the proposal is there.
        return [false, self::json(['proposal_id' => $proposal->id, 'new' => $new,
            'message' => $new ? 'Filed – the person decides under Vorschläge.' : 'Already filed – it is waiting under Vorschläge, nothing more to do.'])];
    }

    /**
     * A52: an assistant takes back one of its own open proposals.
     *
     * @return array{bool, string}
     */
    private static function withdraw(string $id, Request $outer): array
    {
        $key = $outer->attributes->get(AuthenticateAccessKey::ATTRIBUTE);
        $proposal = Proposal::query()->where('status', Proposal::OPEN)->whereKey($id)->first();
        if ($proposal === null || $proposal->assistant === null || $proposal->assistant !== $key?->name) {
            return [true, (string) __('assistant.propose_unknown.proposal')];
        }
        $proposal->update(['status' => Proposal::WITHDRAWN, 'decided_at' => now()]);
        Badges::forget();

        return [false, self::json(['proposal_id' => $proposal->id, 'message' => 'Withdrawn.'])];
    }

    /** @param  array<mixed>  $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A page as a picture (right "Seitenbilder ansehen").
     *
     * @return array{bool, string|list<array<string, string>>}
     */
    private static function look(string $id, int $page, CurrentOrg $org): array
    {
        $document = BankApiController::visible($org)->whereKey($id)->first();
        if ($document === null) {
            return [true, (string) __('assistant.propose_unknown.document')];
        }
        $files = $document->files()->orderBy('page_no')->get();
        // A document of several photos has one file per page; a PDF has its pages inside.
        $file = $files->count() > 1 ? $files->get($page - 1) : $files->first();
        $seen = $file === null ? null : Straightener::look($file, $files->count() > 1 ? 1 : $page);
        if ($seen === null) {
            return [true, (string) __('assistant.no_picture')];
        }
        $pages = $files->count() > 1 ? $files->count() : $seen['pages'];

        return [false, [
            ['type' => 'image', 'data' => base64_encode($seen['jpeg']), 'mimeType' => 'image/jpeg'],
            ['type' => 'text', 'text' => 'Page '.$page.' of '.$pages.', '.$seen['width'].' x '.$seen['height'].' px.'],
        ]];
    }

    private static function hasPhoto(string $id): bool
    {
        return DocumentFile::query()->where('document_id', $id)->whereIn('mime', Straightener::PHOTOS)->exists();
    }

    /** A number kept inside its range, as text for the payload; '' when none was given. */
    private static function number(string $raw, float $min, float $max): string
    {
        return is_numeric($raw) ? (string) round(max($min, min($max, (float) $raw)), 4) : '';
    }
}
