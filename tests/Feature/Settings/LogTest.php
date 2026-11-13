<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLog;
use App\Domain\Log\LogEntry;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Http\Badges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Point 115, A224-A234: Einstellungen → Log – logbooks, levels, filters, export, reset, browser errors, badge.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
});

it('lists events and errors of every logbook and filters them', function (): void {
    LogEntry::write('general', 'event', 'Dokument hochgeladen: a.pdf', orgId: $this->admin->org_id);
    LogEntry::write('general', 'error', 'RuntimeException: kaputt', orgId: $this->admin->org_id);
    LogEntry::write('updates', 'event', 'Update gestartet', orgId: $this->admin->org_id);

    $this->get('/settings/log')->assertOk()->assertSee('Dokument hochgeladen: a.pdf')->assertSee('RuntimeException: kaputt')->assertSee('Update gestartet');
    $this->get('/settings/log?level=errors')->assertOk()->assertSee('RuntimeException: kaputt')->assertDontSee('Dokument hochgeladen: a.pdf');
    $this->get('/settings/log/updates')->assertOk()->assertSee('Update gestartet')->assertDontSee('RuntimeException: kaputt');
    $this->get('/settings/log?q=hochgeladen')->assertOk()->assertSee('a.pdf')->assertDontSee('Update gestartet');
});

it('exports what the filter shows as CSV', function (): void {
    LogEntry::write('general', 'error', 'Fehler eins', orgId: $this->admin->org_id);
    LogEntry::write('general', 'event', 'Ereignis zwei', orgId: $this->admin->org_id);

    $csv = $this->get('/settings/log/export?level=errors')->assertOk()->streamedContent();
    expect($csv)->toContain('Fehler eins')->not->toContain('Ereignis zwei')->toContain('Meldung');
});

it('empties the ticked logbooks and notes who did it', function (): void {
    LogEntry::write('general', 'event', 'alt', orgId: $this->admin->org_id);
    LogEntry::write('updates', 'event', 'bleibt', orgId: $this->admin->org_id);

    $this->post('/settings/log/reset', ['books' => ['general']])->assertRedirect('/settings/log');
    expect(DB::table('log_entries')->pluck('message')->sort()->values()->all())->toBe(['Logbücher geleert: Allgemein', 'bleibt']);
});

it('takes browser errors and counts new errors on the menu', function (): void {
    $this->postJson('/log/client', ['message' => 'TypeError: x is undefined', 'where' => 'app.js:12'])->assertOk();
    expect(DB::table('log_entries')->where('level', 'error')->value('message'))->toBe('Browser: TypeError: x is undefined (app.js:12)');

    $this->get('/documents')->assertSee('badge-alert', false);
    $this->get('/settings/log');
    expect(Badges::counts(app(CurrentOrg::class))[Badges::LOG] ?? 0)->toBe(0);
});

it('logs uploads through the audit trail and keeps only settings managers out', function (): void {
    app(AuditLog::class)->record('document.uploaded', orgId: $this->admin->org_id, userId: $this->admin->user_id);
    expect(DB::table('log_entries')->value('message'))->toBe('Dokument hochgeladen');

    $member = Membership::factory()->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($member->user)->get('/settings/log')->assertForbidden();
});
