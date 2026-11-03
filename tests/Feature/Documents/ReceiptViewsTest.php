<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| UI-APPLY: the six views of the receipt list and the settings page.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    Document::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'bon.jpg']);
});

it('keeps the chosen view per person and shows the receipt in every view', function (string $view, string $marker): void {
    $this->actingAs($this->admin->user)->from('/')->post('/documents/view', ['view' => $view])->assertRedirect('/');

    expect($this->admin->user->fresh()->receipt_view)->toBe($view);
    $this->get('/')->assertOk()->assertSee($marker, false)->assertSee('bon.jpg');
})->with([
    ['details', 'class="view-details"'],
    ['list', 'class="view-list"'],
    ['small', 'view-tiles view-small'],
    ['large', 'view-tiles view-large'],
    ['timeline', 'class="timeline"'],
    ['calendar', 'class="calendar"'],
]);

it('refuses unknown views and moves the calendar by month', function (): void {
    $this->actingAs($this->admin->user)->post('/documents/view', ['view' => 'gross'])->assertSessionHasErrors('view');
    $this->post('/documents/view', ['view' => 'calendar']);

    $this->get('/?month=2020-01')->assertOk()->assertSee('Januar 2020')->assertDontSee('bon.jpg');
    $this->get('/')->assertSee('bon.jpg');
});

it('shows only the settings a person may open', function (): void {
    $this->actingAs($this->admin->user)->get('/settings')->assertOk()->assertSee('Personen')->assertSee('Update')->assertSee('Handy-Test');

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->get('/settings')->assertOk()
        ->assertDontSee('Personen')->assertDontSee('Konten und Import')->assertSee('Handy-Test');
});
