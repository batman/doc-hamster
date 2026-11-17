<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| D-040: an assistant connects by clicks – only while the owner keeps the door open; it registers itself, a person allows
| it on the consent page, the code needs its PKCE verifier and works once, the MCP address speaks with the token, a
| refresh replaces both tokens, closing the door disconnects it.
*/
uses(RefreshDatabase::class)->group('db');

const CALLBACK = 'https://assistant.example.com/callback';

beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = Membership::factory()->owner()->create();
    app(CurrentOrg::class)->set($this->owner);
    $account = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
    $this->booking = Transaction::query()->create(['bank_account_id' => $account->id, 'booking_date' => '2026-11-02', 'amount_minor' => -5400,
        'currency' => 'EUR', 'counterparty' => 'Muster GmbH', 'fingerprint' => Str::random(24)]);
    $this->document = Document::query()->create(['uploaded_by' => $this->owner->user_id, 'title' => 'rechnung.pdf']);
});

function challengeOf(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/** Registers the assistant, allows it on the consent page and returns its client id and the code. */
function allowAssistant(Membership $member, string $verifier, string $scope = 'link'): array
{
    app('auth')->forgetGuards();
    $clientId = test()->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CALLBACK], 'token_endpoint_auth_method' => 'none'])
        ->assertCreated()->json('client_id');
    $ask = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CALLBACK, 'state' => 'xyz',
        'code_challenge' => challengeOf($verifier), 'code_challenge_method' => 'S256'];
    test()->actingAs($member->user)->get('/oauth/authorize?'.http_build_query($ask))->assertOk()->assertSee('„Claude“ möchte auf docHamster zugreifen');
    $page = test()->post('/oauth/authorize', [...$ask, 'decision' => 'allow', 'scope' => $scope])->assertOk()->getContent();
    preg_match('/code=([A-Za-z0-9]+)/', $page, $match);
    expect($page)->toContain('state=xyz')->and($match)->not->toBeEmpty();
    app('auth')->forgetGuards();

    return [$clientId, $match[1]];
}

function mcp(string $token, string $method, array $params = []): array
{
    return test()->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params])->assertOk()->json();
}

it('stays closed until the owner opens it, and only the owner may', function (): void {
    $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
    $this->postJson('/oauth/register', ['redirect_uris' => [CALLBACK]])->assertNotFound();

    $member = Membership::factory()->create(['org_id' => $this->owner->org_id]);
    $this->actingAs($member->user)->get('/settings/assistant')->assertForbidden();
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1'])->assertOk();

    $this->get('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJson(['token_endpoint' => route('oauth.token'), 'code_challenge_methods_supported' => ['S256']]);
    $this->get('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJson(['resource' => route('mcp')]);
});

it('connects by consent with PKCE, speaks MCP and links, refreshes, and closing disconnects', function (): void {
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1'])->assertOk();
    $verifier = Str::random(64);
    [$clientId, $code] = allowAssistant($this->owner, $verifier);

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="'.route('oauth.resource', ['path' => 'mcp']).'"');
    $exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => CALLBACK, 'client_id' => $clientId];
    $this->post('/oauth/token', [...$exchange, 'code_verifier' => Str::random(64)])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    // A wrong verifier used the code up: the next one comes from a fresh consent.
    [$clientId, $code] = allowAssistant($this->owner, $verifier);
    $exchange = [...$exchange, 'client_id' => $clientId, 'code' => $code];
    $tokens = $this->post('/oauth/token', [...$exchange, 'code_verifier' => $verifier])->assertOk()->json();
    expect($tokens)->toMatchArray(['token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'link']);
    $this->post('/oauth/token', [...$exchange, 'code_verifier' => $verifier])->assertStatus(400);

    expect(mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18'])['result']['protocolVersion'])->toBe('2025-06-18')
        ->and(array_column(mcp($tokens['access_token'], 'tools/list')['result']['tools'], 'name'))->toContain('list_transactions', 'link_document');
    $linked = mcp($tokens['access_token'], 'tools/call', ['name' => 'link_document', 'arguments' => ['transaction_id' => $this->booking->id, 'document_id' => $this->document->id]]);
    expect($linked['result']['isError'])->toBeFalse();
    app(CurrentOrg::class)->set($this->owner);
    expect(TransactionMatch::query()->where('transaction_id', $this->booking->id)->value('state'))->toBe(TransactionMatch::CONFIRMED);

    $fresh = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])->assertOk()->json();
    $this->withToken($tokens['access_token'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    expect(mcp($fresh['access_token'], 'ping'))->toHaveKey('result');

    app('auth')->forgetGuards();
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '0'])->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($fresh['access_token'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
});

it('reads only with a "Nur lesen" connection', function (): void {
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1'])->assertOk();
    $verifier = Str::random(64);
    [$clientId, $code] = allowAssistant($this->owner, $verifier, 'read');
    $token = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => CALLBACK,
        'client_id' => $clientId, 'code_verifier' => $verifier])->assertOk()->json('access_token');

    $open = mcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['status' => 'open']]);
    expect($open['result']['isError'])->toBeFalse()->and($open['result']['content'][0]['text'])->toContain($this->booking->id);
    $refused = mcp($token, 'tools/call', ['name' => 'link_document', 'arguments' => ['transaction_id' => $this->booking->id, 'document_id' => $this->document->id]]);
    expect($refused['result']['isError'])->toBeTrue()->and($refused['result']['content'][0]['text'])->toBe('Dieser Schlüssel darf nur lesen.');
});
