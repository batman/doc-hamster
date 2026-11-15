<?php

declare(strict_types=1);

use App\Domain\Dashboard\DashboardLayout;
use App\Domain\Dashboard\UserDashboard;
use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Point 233, A395-A397: notes columns and notes pinned to Home as their own widgets.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    app(CurrentOrg::class)->set($this->admin);
    $this->actingAs($this->admin->user)->get('/notes')->assertOk();
    $this->post('/notes/columns', ['name' => 'Erledigt']);
    $this->first = NoteColumn::query()->withoutGlobalScope(OrgScope::class)->orderBy('sort')->firstOrFail();
    $this->done = NoteColumn::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Erledigt')->firstOrFail();
    $this->post('/notes', ['title' => 'Einkauf', 'body' => "Milch\nBrot", 'column' => $this->first->id]);
    $this->note = Note::query()->withoutGlobalScope(OrgScope::class)->where('title', 'Einkauf')->firstOrFail();
});

$keys = static fn (string $userId): array => array_map(static fn (array $w): string => DashboardLayout::key($w['type'], $w['ref'] ?? null), UserDashboard::of($userId)->cleanLayout()['widgets']);

it('pins a column and a note to Home, once each, and takes them off again (A395, A396)', function () use ($keys): void {
    $this->postJson('/dashboard/notes/pin', ['type' => 'note_column', 'id' => $this->first->id, 'pinned' => 1])->assertOk()->assertJsonPath('pinned', true);
    $this->postJson('/dashboard/notes/pin', ['type' => 'note_column', 'id' => $this->first->id, 'pinned' => 1])->assertOk();
    $this->postJson('/dashboard/notes/pin', ['type' => 'note', 'id' => $this->note->id, 'pinned' => 1])->assertOk();
    $this->postJson('/dashboard/notes/pin', ['type' => 'notes', 'id' => $this->note->id, 'pinned' => 1])->assertStatus(422);
    expect(array_slice($keys($this->admin->user_id), -2))->toBe(['note_column:'.$this->first->id, 'note:'.$this->note->id]);

    $this->get('/')->assertOk()
        ->assertSee('data-ref="'.$this->first->id.'"', false)
        ->assertSee(route('notes.index', ['note' => $this->note->id]), false)
        ->assertSee('data-dash-note-text="'.route('notes.text', $this->note->id).'"', false);
    $this->get('/notes?note='.$this->note->id)->assertSee('data-focus-note="'.$this->note->id.'"', false)->assertSee('aria-pressed="true"', false);

    // The layout keeps them (drag, size); a pin cannot be made up through the layout.
    $this->postJson('/dashboard/layout', ['widgets' => [
        ['type' => 'note', 'ref' => $this->note->id, 'size' => 'l'],
        ['type' => 'note_column', 'ref' => $this->done->id, 'size' => 's'],
    ]])->assertOk();
    expect(UserDashboard::of($this->admin->user_id)->cleanLayout()['widgets'])->toBe([['type' => 'note', 'size' => 'l', 'ref' => $this->note->id]]);

    $this->postJson('/dashboard/notes/pin', ['type' => 'note', 'id' => $this->note->id, 'pinned' => 0])->assertOk();
    expect($keys($this->admin->user_id))->toBe([]);
});

it('keeps a pinned note when it moves and drops widgets of deleted columns and notes (A397)', function () use ($keys): void {
    $this->postJson('/dashboard/notes/pin', ['type' => 'note_column', 'id' => $this->first->id, 'pinned' => 1])->assertOk();
    $this->postJson('/dashboard/notes/pin', ['type' => 'note', 'id' => $this->note->id, 'pinned' => 1])->assertOk();

    $this->postJson('/notes/'.$this->note->id.'/move', ['column' => $this->done->id, 'order' => [$this->note->id]])->assertOk();
    $this->post('/notes/columns/'.$this->first->id.'/delete')->assertRedirect();
    expect(array_values(array_filter($keys($this->admin->user_id), static fn (string $k): bool => str_contains($k, ':'))))->toBe(['note:'.$this->note->id]);

    $this->post('/notes/'.$this->note->id.'/delete')->assertRedirect();
    expect(array_filter($keys($this->admin->user_id), static fn (string $k): bool => str_contains($k, ':')))->toBe([]);
});

it('pins only notes the person sees and shows others read-only (A396)', function (): void {
    $this->post('/notes/'.$this->note->id, ['title' => 'Einkauf', 'shared' => 1]);
    $private = Note::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'user_id' => $this->admin->user_id, 'title' => 'Geheim', 'shared' => false]);
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);

    $this->actingAs($clerk->user)->postJson('/dashboard/notes/pin', ['type' => 'note', 'id' => $private->id, 'pinned' => 1])->assertNotFound();
    $this->postJson('/dashboard/notes/pin', ['type' => 'note', 'id' => $this->note->id, 'pinned' => 1])->assertOk();
    $this->get('/')->assertOk()->assertSee('Milch')->assertDontSee('data-dash-note-text', false);
});
