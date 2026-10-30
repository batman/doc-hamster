<?php

declare(strict_types=1);

use App\Infrastructure\Environment\SystemProbe;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeSystemProbe;

/*
| P1-7: security headers, API CSRF rule (D-017), health endpoint.
*/

it('sends the security headers, HSTS only over HTTPS', function (): void {
    $this->get('http://dochamster.example.com/')
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($this->get('/')->headers->get('Content-Security-Policy'))->not->toContain('unsafe-inline');

    $this->get('https://dochamster.example.com/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('trusts the HTTPS flag of a local proxy, not of strangers', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('http://dochamster.example.com/')
        ->assertHeader('Strict-Transport-Security');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('http://dochamster.example.com/')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('accepts state-changing API requests only from our origin with the client header', function (): void {
    Route::middleware('api.same-origin')->post('/api/_test', fn () => 'ok');
    $origin = 'https://dochamster.example.com';

    $this->post($origin.'/api/_test')->assertForbidden();
    $this->post($origin.'/api/_test', [], ['Origin' => 'https://evil.example', 'X-DocHamster-Client' => '1'])->assertForbidden();
    $this->post($origin.'/api/_test', [], ['Origin' => $origin, 'X-DocHamster-Client' => '1', 'Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    $this->post($origin.'/api/_test', [], ['Origin' => $origin, 'X-DocHamster-Client' => '1'])->assertOk();
});

it('reports health as ok/fail only, 503 when a check fails', function (): void {
    $probe = new FakeSystemProbe;
    $this->app->instance(SystemProbe::class, $probe);

    $this->getJson('/health')->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'storage' => 'ok', 'worker' => 'ok']]);

    $probe->databaseVersion = null;

    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.database', 'fail');
});
