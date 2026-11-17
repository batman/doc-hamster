<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Api\BankApiController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Middleware\AuthenticateAccessKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The tools an assistant sees at the MCP address (D-040). Each one is a call of the interface (api/v1) – the same
 * controllers, the same rights, restriction and organisation, the same audit log. Text only; nothing deletes.
 */
final class McpTools
{
    /**
     * name => [title, description, input schema, read only]
     *
     * @return array<string, array{string, string, array<string, mixed>, bool}>
     */
    public static function definitions(): array
    {
        $id = ['type' => 'string', 'description' => 'The id (ULID) as docHamster gives it.'];
        $date = static fn (string $what): array => ['type' => 'string', 'description' => $what.' (YYYY-MM-DD).'];
        $page = ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of the list (see next_page in the answer).'];
        $none = ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
        $pair = ['type' => 'object', 'properties' => ['transaction_id' => [...$id, 'description' => 'The booking.'], 'document_id' => [...$id, 'description' => 'The document.']],
            'required' => ['transaction_id', 'document_id'], 'additionalProperties' => false];
        $one = static fn (string $what): array => ['type' => 'object', 'properties' => ['id' => [...$id, 'description' => $what]], 'required' => ['id'], 'additionalProperties' => false];

        return [
            'whoami' => ['Who am I in docHamster', 'Whose connection this is, the organisation, and whether it may link (scope "link") or only read.', $none, true],
            'list_accounts' => ['Bank accounts', 'The bank accounts with their number of bookings per status (open = no document, suggested = docHamster suggests one, linked, none = no document needed).', $none, true],
            'list_transactions' => ['Bookings', 'Bank bookings, newest first, 100 per page. Amounts: amount_minor in cents and amount as a decimal string; negative = money out.', ['type' => 'object', 'properties' => [
                'account_id' => [...$id, 'description' => 'Only this account.'],
                'status' => ['type' => 'string', 'enum' => ['open', 'suggested', 'linked', 'none'], 'description' => 'open = no document yet; suggested = docHamster suggests one; linked; none = no document needed.'],
                'flow' => ['type' => 'string', 'enum' => ['in', 'out'], 'description' => 'Only money in or only money out.'],
                'from' => $date('First booking day'), 'to' => $date('Last booking day'),
                'search' => ['type' => 'string', 'description' => 'Text in counterparty, purpose, reference or note.'], 'page' => $page,
            ], 'additionalProperties' => false], true],
            'get_transaction' => ['One booking', 'One booking with its documents (state confirmed = linked, suggested = docHamster\'s suggestion) and up to 10 further candidate documents with a score (0–100) and the reasons.', $one('The booking.'), true],
            'list_folders' => ['Folders', 'The document folders; bank_matching tells whether its documents may be linked to bookings.', $none, true],
            'list_documents' => ['Documents', 'Documents the person may see, 50 per page, with what docHamster read from them (amount, date, sender, IBAN, invoice number) and the bookings they are linked to.', ['type' => 'object', 'properties' => [
                'free_only' => ['type' => 'boolean', 'description' => 'Only documents that may be linked and are not linked to any booking yet.'],
                'folder_id' => [...$id, 'description' => 'Only this folder.'],
                'from' => $date('First document date'), 'to' => $date('Last document date'),
                'search' => ['type' => 'string', 'description' => 'Text in the title, the recognised text or a property.'], 'page' => $page,
            ], 'additionalProperties' => false], true],
            'get_document' => ['One document', 'One document with all its properties and its recognised text (no scan images).', $one('The document.'), true],
            'link_document' => ['Link a document to a booking', 'Links the document to the booking (confirmed). Needs the scope "link". Can be undone with unlink_document.', $pair, false],
            'confirm_suggestion' => ['Confirm a suggestion', 'Turns docHamster\'s suggestion (state suggested) for this booking and document into a link. Needs the scope "link".', $pair, false],
            'reject_suggestion' => ['Reject a suggestion', 'Removes docHamster\'s suggestion for this booking and document; it is not suggested again. Needs the scope "link".', $pair, false],
            'unlink_document' => ['Unlink a document', 'Removes the link (or suggestion) between the booking and the document. Needs the scope "link".', $pair, false],
        ];
    }

    /** @return list<array<string, mixed>> the tools as MCP lists them */
    public static function listed(): array
    {
        $tools = [];
        foreach (self::definitions() as $name => [$title, $description, $schema, $readOnly]) {
            $tools[] = ['name' => $name, 'title' => $title, 'description' => $description, 'inputSchema' => $schema,
                'annotations' => $readOnly ? ['readOnlyHint' => true, 'openWorldHint' => false]
                    : ['readOnlyHint' => false, 'destructiveHint' => $name === 'unlink_document', 'idempotentHint' => true, 'openWorldHint' => false]];
        }

        return $tools;
    }

    /**
     * Runs a tool as an interface call of the same person.
     *
     * @param  array<string, mixed>  $args
     * @return array{bool, string} failed?, the answer as text (JSON or the reason)
     */
    public static function call(string $name, array $args, Request $outer): array
    {
        $text = static fn (string $key): string => is_string($args[$key] ?? null) ? $args[$key] : '';
        [$class, $method, $params, $input, $verb] = match ($name) {
            'whoami' => [BankApiController::class, 'me', [], [], 'GET'],
            'list_accounts' => [BankApiController::class, 'accounts', [], [], 'GET'],
            'list_transactions' => [BankApiController::class, 'transactions', [], ['account' => $args['account_id'] ?? null, 'status' => $args['status'] ?? null,
                'flow' => $args['flow'] ?? null, 'from' => $args['from'] ?? null, 'to' => $args['to'] ?? null, 'q' => $args['search'] ?? null, 'page' => $args['page'] ?? null], 'GET'],
            'get_transaction' => [BankApiController::class, 'transaction', ['id' => $text('id')], [], 'GET'],
            'list_folders' => [DocumentApiController::class, 'folders', [], [], 'GET'],
            'list_documents' => [DocumentApiController::class, 'documents', [], ['free' => ($args['free_only'] ?? false) === true ? '1' : null, 'folder' => $args['folder_id'] ?? null,
                'from' => $args['from'] ?? null, 'to' => $args['to'] ?? null, 'q' => $args['search'] ?? null, 'page' => $args['page'] ?? null], 'GET'],
            'get_document' => [DocumentApiController::class, 'document', ['id' => $text('id')], [], 'GET'],
            'link_document' => [BankApiController::class, 'link', ['id' => $text('transaction_id')], ['document_id' => $text('document_id')], 'POST'],
            'confirm_suggestion' => [BankApiController::class, 'confirm', ['id' => $text('transaction_id')], ['document_id' => $text('document_id')], 'POST'],
            'reject_suggestion' => [BankApiController::class, 'reject', ['id' => $text('transaction_id')], ['document_id' => $text('document_id')], 'POST'],
            'unlink_document' => [BankApiController::class, 'unlink', ['id' => $text('transaction_id')], ['document_id' => $text('document_id')], 'POST'],
            default => [null, null, [], [], 'GET'],
        };
        if ($class === null || $method === null) {
            return [true, 'Unknown tool: '.$name];
        }
        if (isset($params['id']) && $params['id'] === '') {
            return [true, 'Missing: '.($method === 'transaction' || $method === 'document' ? 'id' : 'transaction_id')];
        }

        $request = Request::create('/api/v1', $verb, array_filter($input, static fn (mixed $v): bool => $v !== null && $v !== ''), [], [], ['REMOTE_ADDR' => $outer->ip()]);
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

        return [false, $response instanceof JsonResponse ? (string) json_encode($response->getData(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''];
    }
}
