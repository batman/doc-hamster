<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
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
    $this->actingAs($this->admin->user)->from('/documents')->post('/documents/view', ['view' => $view])->assertRedirect('/documents');

    expect($this->admin->user->fresh()->receipt_view)->toBe($view);
    $this->get('/documents')->assertOk()->assertSee($marker, false)->assertSee('bon.jpg');
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

    $this->get('/documents?month=2020-01')->assertOk()->assertSee('Januar 2020')->assertDontSee('bon.jpg');
    $this->get('/documents')->assertSee('bon.jpg');
});

it('shows only the settings a person may open', function (): void {
    $this->actingAs($this->admin->user)->get('/settings')->assertOk()->assertSee('Personen &amp; Rollen', false)->assertSee('Update');
    $this->get('/settings/phone')->assertOk()->assertSee('Handy-Test')->assertSee('Handy verbinden');
    $this->get('/settings/tags')->assertRedirect(route('tags.index'));

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->get('/settings')->assertOk()
        ->assertDontSee('Personen &amp; Rollen', false)->assertDontSee('Bank</span>', false)->assertSee('Handy');
    $this->get('/settings/people')->assertNotFound();
});

it('opens a receipt beside the list, saves title, note and tags, and deletes into the trash', function (): void {
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'bon.jpg')->firstOrFail();
    $tag = Tag::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->admin->org_id)->firstOrFail();

    $this->actingAs($this->admin->user)->get('/documents/'.$document->id)->assertOk()
        ->assertSee('class="split-detail"', false)->assertSee('Verlauf');

    $this->post('/documents/'.$document->id, ['title' => 'Tankquittung', 'note' => 'Fahrt nach Köln', 'tags' => [$tag->id]])->assertRedirect();
    $document->refresh();
    expect([$document->title, $document->note, $document->tags()->pluck('tags.id')->all()])->toBe(['Tankquittung', 'Fahrt nach Köln', [$tag->id]]);

    $this->post('/documents/'.$document->id.'/delete')->assertRedirect(route('documents.index'));
    expect(Document::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0)
        ->and(Document::withTrashed()->withoutGlobalScope(OrgScope::class)->count())->toBe(1);
});

it('colours tags: a palette colour by chance, any colour by hand, and serves them as a stylesheet', function (): void {
    $this->actingAs($this->admin->user)->post('/tags', ['name' => 'Reise'])->assertRedirect();
    $travel = Tag::query()->where('name', 'Reise')->firstOrFail();
    expect(Tag::PALETTE)->toContain($travel->color);

    $this->post('/tags/'.$travel->id, ['name' => 'Reise', 'color' => '#ff7622', 'color_hex' => '#12345A'])->assertRedirect();
    expect($travel->fresh()->color)->toBe('#12345a');
    $this->post('/tags/'.$travel->id, ['name' => 'Reise', 'color_hex' => 'red;}'])->assertSessionHasErrors('color');

    $this->get('/tags.css')->assertOk()->assertSee('.tag-'.$travel->id.'{--tag:#12345a;--tag-text:#ffffff}', false);
});
