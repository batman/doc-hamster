<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Api\AccessKey;
use App\Domain\Assistant\AssistantAccess;
use App\Domain\Assistant\OAuthClient;
use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\PersonAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Connecting an assistant by clicks (D-040): the OAuth 2.1 sign-in that Claude's "Konnektor hinzufügen" (and any MCP
 * client) follows – where to sign in (RFC 9728, RFC 8414), registering itself (RFC 7591), the person's "Erlauben" on
 * docHamster's own consent page (authorization code with PKCE S256 only) and the tokens (a connection = an access key
 * with a one-hour access token and a rotating refresh token). Every address answers "not here" while no organisation
 * has "Zugang für Assistenten" open.
 */
final class OAuthController
{
    private const CODE_MINUTES = 10;

    public function resource(): JsonResponse
    {
        self::open();

        return response()->json([
            'resource' => route('mcp'),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => AccessKey::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'docHamster',
        ]);
    }

    public function server(): JsonResponse
    {
        self::open();

        return response()->json([
            'issuer' => self::issuer(),
            'authorization_endpoint' => route('oauth.authorize'),
            'token_endpoint' => route('oauth.token'),
            'registration_endpoint' => route('oauth.register'),
            'scopes_supported' => AccessKey::SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    /** RFC 7591: an assistant registers itself; this grants nothing yet. */
    public function register(Request $request): JsonResponse
    {
        self::open();
        $uris = $request->input('redirect_uris');
        if (! is_array($uris) || $uris === [] || count($uris) > 5) {
            return self::error('invalid_redirect_uri', 'redirect_uris: one to five addresses.', 400);
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || strlen($uri) > 500 || ! OAuthClient::acceptableRedirect($uri)) {
                return self::error('invalid_redirect_uri', 'Only https addresses (http only for localhost).', 400);
            }
        }
        $method = $request->input('token_endpoint_auth_method', 'client_secret_basic');
        if (! in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            return self::error('invalid_client_metadata', 'token_endpoint_auth_method not supported.', 400);
        }
        $name = trim((string) $request->input('client_name', ''));
        $name = $name === '' ? 'Assistent' : mb_substr(strip_tags($name), 0, 100);
        [$client, $secret] = OAuthClient::register($name, array_values($uris), $method !== 'none');

        return response()->json(array_filter([
            'client_id' => $client->client_id,
            'client_secret' => $secret,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ], static fn (mixed $v): bool => $v !== null), 201, ['Cache-Control' => 'no-store']);
    }

    /** The consent page: who asks, for which organisation, "Nur lesen" or "Lesen + Verknüpfen", Erlauben / Ablehnen. */
    public function authorize(Request $request, CurrentOrg $org): View
    {
        $ask = $this->ask($request);
        if (is_string($ask)) {
            return view('assistant.oauth-problem', ['message' => $ask]);
        }
        if (! AssistantAccess::enabled($org->organization())) {
            return view('assistant.oauth-problem', ['message' => __('assistant.closed_page')]);
        }
        if (($error = $this->unsupported($request)) !== null) {
            return $this->back($ask['client'], $ask['redirect'], ['error' => $error, 'state' => $request->query('state')]);
        }

        return view('assistant.consent', [
            'client' => $ask['client'],
            'host' => (string) parse_url($ask['redirect'], PHP_URL_HOST),
            'organisation' => $org->organization()->name,
            'rights' => AssistantAccess::rights($org->organization()),
            'person' => $org->membership()->user?->display_name,
            'fields' => [
                'client_id' => $ask['client']->client_id,
                'redirect_uri' => $ask['redirect'],
                'state' => (string) $request->query('state', ''),
                'code_challenge' => (string) $request->query('code_challenge'),
                'resource' => (string) $request->query('resource', ''),
            ],
        ]);
    }

    /** "Erlauben" or "Ablehnen": back to the assistant with a one-time code (10 minutes) or the refusal. */
    public function approve(Request $request, CurrentOrg $org, AuditLog $audit): View
    {
        $ask = $this->ask($request);
        if (is_string($ask)) {
            return view('assistant.oauth-problem', ['message' => $ask]);
        }
        $data = $request->validate([
            'decision' => ['required', 'in:allow,deny'],
            'code_challenge' => ['required', 'string', 'min:43', 'max:128'], 'state' => ['nullable', 'string', 'max:500'],
            'resource' => ['nullable', 'string', 'max:500'],
        ]);
        $state = $data['state'] ?? null;
        if ($data['decision'] === 'deny' || ! AssistantAccess::enabled($org->organization())) {
            return $this->back($ask['client'], $ask['redirect'], ['error' => 'access_denied', 'state' => $state]);
        }

        $code = Str::random(64);
        Cache::put(self::codeKey($code), encrypt([
            'client' => $ask['client']->client_id, 'redirect' => $ask['redirect'], 'challenge' => $data['code_challenge'],
            'scope' => AccessKey::READ, 'resource' => $data['resource'] ?? '', 'org' => $org->id(), 'user' => $org->membership()->user_id,
        ]), now()->addMinutes(self::CODE_MINUTES));
        $audit->record('assistant.allowed', orgId: $org->id(), userId: $org->membership()->user_id, meta: ['client' => $ask['client']->name, 'rights' => AssistantAccess::rights($org->organization())], ip: $request->ip());

        return $this->back($ask['client'], $ask['redirect'], ['code' => $code, 'state' => $state]);
    }

    /** The token endpoint: a code (with PKCE) or a refresh token becomes a fresh access token and refresh token. */
    public function token(Request $request, AuditLog $audit): JsonResponse
    {
        self::open();
        [$clientId, $secret] = self::credentials($request);
        $client = OAuthClient::byClientId($clientId);
        if ($client === null || ! $client->secretMatches($secret)) {
            return self::error('invalid_client', 'Unknown assistant or wrong secret.', 401);
        }
        $client->forceFill(['last_used_at' => now()])->saveQuietly();

        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchange($request, $client, $audit),
            'refresh_token' => $this->refresh($request, $client),
            default => self::error('unsupported_grant_type', 'Only authorization_code and refresh_token.', 400),
        };
    }

    private function exchange(Request $request, OAuthClient $client, AuditLog $audit): JsonResponse
    {
        $code = (string) $request->input('code');
        $sealed = $code === '' ? null : Cache::pull(self::codeKey($code));
        $ask = is_string($sealed) ? decrypt($sealed) : null;
        if (! is_array($ask) || $ask['client'] !== $client->client_id || $ask['redirect'] !== $request->input('redirect_uri')) {
            return self::error('invalid_grant', 'The code is unknown, used or expired.', 400);
        }
        if (! self::pkce((string) $request->input('code_verifier'), (string) $ask['challenge'])) {
            return self::error('invalid_grant', 'code_verifier does not match.', 400);
        }
        $resource = (string) $request->input('resource', '');
        if ($resource !== '' && $ask['resource'] !== '' && $resource !== $ask['resource']) {
            return self::error('invalid_target', 'resource differs from the one allowed.', 400);
        }
        $membership = Membership::query()->where('org_id', $ask['org'])->where('user_id', $ask['user'])->whereNull('revoked_at')->first();
        if ($membership === null || ! PersonAccess::allows($membership) || ! AssistantAccess::enabled(Organization::query()->find((string) $ask['org']))) {
            return self::error('invalid_grant', 'The access is closed or the person may not any more.', 400);
        }

        [$access, $token, $refresh] = AccessKey::connect($ask['org'], $ask['user'], $client->name, (string) $ask['scope'], $client->client_id);
        $audit->record('assistant.connected', orgId: $ask['org'], userId: $ask['user'], subject: $access, meta: ['client' => $client->client_id, 'scope' => $access->scope], ip: $request->ip());

        return self::tokens($access, $token, $refresh);
    }

    private function refresh(Request $request, OAuthClient $client): JsonResponse
    {
        $access = AccessKey::byRefresh((string) $request->input('refresh_token'), $client->client_id);
        $membership = $access === null ? null : Membership::query()->where('org_id', $access->org_id)->where('user_id', $access->user_id)->whereNull('revoked_at')->first();
        if ($access === null || $membership === null || ! PersonAccess::allows($membership) || ! AssistantAccess::enabled(Organization::query()->find($access->org_id))) {
            return self::error('invalid_grant', 'The connection is gone or closed.', 400);
        }
        [$token, $refresh] = $access->rotate();

        return self::tokens($access, $token, $refresh);
    }

    private static function tokens(AccessKey $access, string $token, string $refresh): JsonResponse
    {
        return response()->json([
            'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => AccessKey::TOKEN_SECONDS,
            'refresh_token' => $refresh, 'scope' => $access->scope,
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * The registered assistant and its redirect address, or why the request is broken (shown here, never sent on).
     *
     * @return array{client: OAuthClient, redirect: string}|string
     */
    private function ask(Request $request): array|string
    {
        $client = OAuthClient::byClientId((string) $request->input('client_id'));
        if ($client === null) {
            return (string) __('assistant.unknown_client');
        }
        $redirect = $request->input('redirect_uri');
        if (($redirect === null || $redirect === '') && count($client->redirect_uris) === 1) {
            $redirect = $client->redirect_uris[0];
        }

        return is_string($redirect) && $client->allowsRedirect($redirect) ? ['client' => $client, 'redirect' => $redirect] : (string) __('assistant.bad_redirect');
    }

    /** What is wrong with the request itself (sent back to the assistant as an OAuth error), or null. */
    private function unsupported(Request $request): ?string
    {
        if ($request->query('response_type') !== 'code') {
            return 'unsupported_response_type';
        }
        $challenge = $request->query('code_challenge');

        return is_string($challenge) && strlen($challenge) >= 43 && $request->query('code_challenge_method') === 'S256' ? null : 'invalid_request';
    }

    /**
     * Back to the assistant. A page that goes on by itself: the site's form-action rule lets a form lead only to
     * docHamster, a page may move on anywhere.
     *
     * @param  array<string, mixed>  $params
     */
    private function back(OAuthClient $client, string $redirect, array $params): View
    {
        $query = http_build_query(array_filter($params, static fn (mixed $v): bool => is_string($v) && $v !== ''));

        return view('assistant.oauth-back', ['client' => $client, 'url' => $redirect.(str_contains($redirect, '?') ? '&' : '?').$query, 'denied' => isset($params['error'])]);
    }

    /** RFC 7636 S256: BASE64URL(SHA256(verifier)) equals the challenge. */
    public static function pkce(string $verifier, string $challenge): bool
    {
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return false;
        }

        return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }

    /** @return array{?string, ?string} client id and secret from the body or HTTP Basic */
    private static function credentials(Request $request): array
    {
        $basic = $request->header('Authorization');
        if (is_string($basic) && str_starts_with($basic, 'Basic ')) {
            $pair = explode(':', (string) base64_decode(substr($basic, 6), true), 2);

            return [urldecode($pair[0]), isset($pair[1]) ? urldecode($pair[1]) : null];
        }
        $id = $request->input('client_id');
        $secret = $request->input('client_secret');

        return [is_string($id) ? $id : null, is_string($secret) ? $secret : null];
    }

    private static function codeKey(string $code): string
    {
        return 'oauth-code:'.hash('sha256', $code);
    }

    private static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    private static function error(string $error, string $description, int $status): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status, ['Cache-Control' => 'no-store']);
    }

    /** While no organisation has the door open, these addresses don't exist. */
    private static function open(): void
    {
        abort_unless(AssistantAccess::anyOpen(), 404);
    }
}
