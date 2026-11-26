<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Org\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The MCP address (D-040, Streamable HTTP): what Claude's "Konnektor hinzufügen" talks to after the click sign-in.
 * JSON-RPC over POST, one plain JSON answer per request (no event stream, no session); the tools are McpTools. The
 * door, the person and the connection's scope are checked before (AuthenticateAccessKey with "mcp").
 */
final class McpController
{
    private const LATEST = '2025-06-18';

    private const KNOWN = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const INSTRUCTIONS = 'docHamster collects receipts and links them to bank bookings. You change nothing yourself: '
        .'every change is a proposal (propose_*) that the person takes over or not under "Vorschläge" – give each a short, '
        .'concrete reason. Linking: list_transactions with status "open", get_transaction for its candidates, get_document when '
        .'unsure, then propose_link. Never ask the person in the chat about documents: file also unsure findings as '
        .'proposals with sure = false and the doubt in the reason; sure = true only when amount, date and counterparty '
        .'clearly fit. Also tidy up where your rights allow: propose_unlink for a link that does not fit, propose_move, '
        .'propose_duplicate (keep the better picture – links and values move to it), propose_delete (Papierkorb only), '
        .'propose_shrink, propose_correction / propose_clear for values the text recognition read wrong, propose_title, '
        .'propose_setting. upload_document puts a receipt or an important e-mail (as PDF) into the Posteingang – the same file is '
        .'never stored twice. Check list_proposals first; a repeated call files nothing twice; withdraw_proposal takes back '
        .'your own mistake. whoami shows which rights the owner ticked.';

    public function handle(Request $request, CurrentOrg $org): JsonResponse|Response
    {
        // DNS rebinding: a browser page of another site never gets an answer.
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '' && parse_url($origin, PHP_URL_HOST) !== $request->getHost()) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Origin not allowed']], 403);
        }
        $message = json_decode($request->getContent(), true);
        if (! is_array($message)) {
            return self::error(null, -32700, 'Parse error', 400);
        }
        if (array_is_list($message)) {
            $answers = array_values(array_filter(array_map(fn (mixed $m): ?array => is_array($m) ? $this->answer($m, $request, $org) : null, $message)));

            return $answers === [] ? response()->noContent(202) : response()->json($answers);
        }
        $answer = $this->answer($message, $request, $org);

        return $answer === null ? response()->noContent(202) : response()->json($answer);
    }

    /** The event stream and session end are not offered. */
    public function other(): Response
    {
        return response()->noContent(405)->header('Allow', 'POST');
    }

    /**
     * @param  array<mixed>  $message
     * @return array<string, mixed>|null null for a notification or a response (nothing to answer)
     */
    private function answer(array $message, Request $request, CurrentOrg $org): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if ($id === null || ! is_string($method)) {
            return null;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        switch ($method) {
            case 'initialize':
                $asked = $params['protocolVersion'] ?? null;

                return self::result($id, [
                    'protocolVersion' => in_array($asked, self::KNOWN, true) ? $asked : self::LATEST,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'dochamster', 'title' => 'docHamster', 'version' => (string) config('dochamster.version', '1')],
                    'instructions' => self::INSTRUCTIONS,
                ]);
            case 'ping':
                return self::result($id, new \stdClass);
            case 'tools/list':
                return self::result($id, ['tools' => McpTools::listed($org)]);
            case 'tools/call':
                $name = is_string($params['name'] ?? null) ? $params['name'] : '';
                if (! array_key_exists($name, McpTools::definitions())) {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool: '.$name]];
                }
                [$failed, $text] = McpTools::call($name, is_array($params['arguments'] ?? null) ? $params['arguments'] : [], $request, $org);

                return self::result($id, ['content' => is_array($text) ? $text : [['type' => 'text', 'text' => $text]], 'isError' => $failed]);
            default:
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: '.$method]];
        }
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
