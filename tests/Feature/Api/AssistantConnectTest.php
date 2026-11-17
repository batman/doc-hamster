<?php

declare(strict_types=1);

use App\Domain\Assistant\Proposal;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| D-040: an assistant connects by clicks – only while the owner keeps the door open with at least one ticked right; it
| registers itself, a person allows it on the consent page, the code needs its PKCE verifier and works once, the MCP
| address speaks with the token, it changes nothing itself but files proposals the owner takes over, a refresh replaces
| both tokens, closing the door disconnects it.
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
function allowAssistant(Membership $member, string $verifier): array
{
    app('auth')->forgetGuards();
    $clientId = test()->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CALLBACK], 'token_endpoint_auth_method' => 'none'])
        ->assertCreated()->json('client_id');
    $ask = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CALLBACK, 'state' => 'xyz',
        'code_challenge' => challengeOf($verifier), 'code_challenge_method' => 'S256'];
    test()->actingAs($member->user)->get('/oauth/authorize?'.http_build_query($ask))->assertOk()->assertSee('„Claude“ möchte auf docHamster zugreifen');
    $page = test()->post('/oauth/authorize', [...$ask, 'decision' => 'allow'])->assertOk()->getContent();
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
    // Nothing ticked: "An" stays closed.
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1'])->assertOk();
    $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1', 'rights' => ['read']])->assertOk();

    $this->get('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJson(['token_endpoint' => route('oauth.token'), 'code_challenge_methods_supported' => ['S256']]);
    $this->get('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJson(['resource' => route('mcp')]);
});

it('connects by consent with PKCE, files a proposal the owner takes over, refreshes, and closing disconnects', function (): void {
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1', 'rights' => ['read', 'link']])->assertOk();
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
    expect($tokens)->toMatchArray(['token_type' => 'Bearer', 'expires_in' => 3600]);
    $this->post('/oauth/token', [...$exchange, 'code_verifier' => $verifier])->assertStatus(400);

    expect(mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18'])['result']['protocolVersion'])->toBe('2025-06-18')
        ->and(array_column(mcp($tokens['access_token'], 'tools/list')['result']['tools'], 'name'))->toContain('list_transactions', 'propose_link')->not->toContain('propose_delete');
    $filed = mcp($tokens['access_token'], 'tools/call', ['name' => 'propose_link', 'arguments' => ['transaction_id' => $this->booking->id, 'document_id' => $this->document->id, 'reason' => 'Betrag passt.']]);
    expect($filed['result']['isError'])->toBeFalse();
    app(CurrentOrg::class)->set($this->owner);
    expect(TransactionMatch::query()->count())->toBe(0);
    $proposal = Proposal::query()->firstOrFail();
    expect($proposal->status)->toBe('open')->and($proposal->reason)->toBe('Betrag passt.');
    // Filed twice: still one.
    mcp($tokens['access_token'], 'tools/call', ['name' => 'propose_link', 'arguments' => ['transaction_id' => $this->booking->id, 'document_id' => $this->document->id, 'reason' => 'x']]);
    expect(Proposal::query()->count())->toBe(1);
    $this->actingAs($this->owner->user)->get('/proposals')->assertOk()->assertSee('Betrag passt.');
    $this->post("/proposals/{$proposal->id}/accept")->assertRedirect('/proposals');
    expect(TransactionMatch::query()->where('transaction_id', $this->booking->id)->value('state'))->toBe(TransactionMatch::CONFIRMED)
        ->and($proposal->fresh()->status)->toBe('accepted');
    app('auth')->forgetGuards();

    $fresh = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])->assertOk()->json();
    $this->withToken($tokens['access_token'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    expect(mcp($fresh['access_token'], 'ping'))->toHaveKey('result');

    app('auth')->forgetGuards();
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '0'])->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($fresh['access_token'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
});

it('offers only what the owner ticked', function (): void {
    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1', 'rights' => ['read']])->assertOk();
    $verifier = Str::random(64);
    [$clientId, $code] = allowAssistant($this->owner, $verifier);
    $token = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => CALLBACK,
        'client_id' => $clientId, 'code_verifier' => $verifier])->assertOk()->json('access_token');

    $open = mcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['status' => 'open']]);
    expect($open['result']['isError'])->toBeFalse()->and($open['result']['content'][0]['text'])->toContain($this->booking->id);
    expect(array_column(mcp($token, 'tools/list')['result']['tools'], 'name'))->not->toContain('propose_link');
    $refused = mcp($token, 'tools/call', ['name' => 'propose_link', 'arguments' => ['transaction_id' => $this->booking->id, 'document_id' => $this->document->id, 'reason' => 'x']]);
    expect($refused['result']['isError'])->toBeTrue()->and($refused['result']['content'][0]['text'])->toContain('nicht angehakt');
});

it('shows a page picture with its right and straightens a photo by proposal, keeping the original (stage 2)', function (): void {
    // A synthetic photo: grey, 400 x 300.
    $image = imagecreatetruecolor(400, 300);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 200, 200));
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    Storage::disk('local')->put('photo.jpg', $jpeg);
    $file = DocumentFile::query()->create(['org_id' => $this->owner->org_id, 'document_id' => $this->document->id, 'path' => 'photo.jpg', 'mime' => 'image/jpeg',
        'size' => strlen($jpeg), 'sha256' => hash('sha256', $jpeg), 'page_no' => 1, 'kind' => 'original']);

    $this->actingAs($this->owner->user)->postJson('/settings/assistant', ['on' => '1', 'rights' => ['read', 'images', 'straighten']])->assertOk();
    $verifier = Str::random(64);
    [$clientId, $code] = allowAssistant($this->owner, $verifier);
    $token = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => CALLBACK,
        'client_id' => $clientId, 'code_verifier' => $verifier])->assertOk()->json('access_token');

    $look = mcp($token, 'tools/call', ['name' => 'get_page_image', 'arguments' => ['document_id' => $this->document->id]]);
    expect($look['result']['content'][0])->toMatchArray(['type' => 'image', 'mimeType' => 'image/jpeg'])
        ->and(bin2hex(substr((string) base64_decode($look['result']['content'][0]['data'], true), 0, 2)))->toBe('ffd8');
    $filed = mcp($token, 'tools/call', ['name' => 'propose_straighten', 'arguments' => ['document_id' => $this->document->id,
        'angle' => 4, 'cx' => 0.5, 'cy' => 0.5, 'w' => 0.6, 'h' => 0.8, 'reason' => 'Um 4° schief, breiter Rand.']]);
    expect($filed['result']['isError'])->toBeFalse();

    app(CurrentOrg::class)->set($this->owner);
    $proposal = Proposal::query()->where('kind', Proposal::STRAIGHTEN)->firstOrFail();
    $this->actingAs($this->owner->user)->get("/proposals/{$proposal->id}/preview/after")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->post("/proposals/{$proposal->id}/accept")->assertRedirect('/proposals');

    $file->refresh();
    expect($proposal->fresh()->status)->toBe('accepted')->and($file->path)->not->toBe('photo.jpg')
        ->and(Document::query()->withTrashed()->where('original_of', $this->document->id)->exists())->toBeTrue();
    $size = getimagesizefromstring((string) Storage::disk('local')->get($file->path));
    // 0.6 x 400 wide and 0.8 x 300 high, cut out of the turned picture.
    expect($size[0])->toBe(240)->and($size[1])->toBe(240);
});
