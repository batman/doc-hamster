<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Domain\Palette\PaletteColor;
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
    $this->actingAs($this->admin->user)->from('/documents')->post('/documents/view', ['view' => $view])->assertRedirect('/documents?view='.$view);

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

it('keeps the view per window in its address; a window without one starts with the last choice', function (): void {
    $this->actingAs($this->admin->user)->post('/documents/view', ['view' => 'list', 'back' => url('/documents?folder=x&page=2')])
        ->assertRedirect('/documents?folder=x&view=list');
    $this->post('/documents/view', ['view' => 'list', 'back' => 'https://evil.example.com/'])->assertRedirect(route('documents.index').'?view=list');

    $this->get('/documents?view=timeline')->assertOk()->assertSee('class="timeline"', false);
    $this->get('/documents')->assertOk()->assertSee('class="view-list"', false);
    expect($this->admin->user->fresh()->receipt_view)->toBe('list');
});

it('refuses unknown views and moves the calendar by month', function (): void {
    $this->actingAs($this->admin->user)->post('/documents/view', ['view' => 'gross'])->assertSessionHasErrors('view');
    $this->post('/documents/view', ['view' => 'calendar']);

    // Point 107: no month before the first document.
    $this->get('/documents?month=2020-01')->assertOk()->assertDontSee('Januar 2020')->assertSee('bon.jpg');
    $this->get('/documents')->assertSee('bon.jpg');
});

it('shows only the settings a person may open', function (): void {
    $this->actingAs($this->admin->user)->get('/settings')->assertOk()->assertSee('Personen &amp; Rollen', false)->assertSee('Update');
    $this->get('/settings/update')->assertOk()->assertSee('Handy-Test');
    $this->get('/settings/tags')->assertRedirect(route('tags.index'));

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->get('/settings')->assertOk()
        ->assertDontSee('Personen &amp; Rollen', false)->assertDontSee('Bank</span>', false)->assertSee('Update');
    $this->get('/settings/people')->assertNotFound();
});

it('opens a receipt beside the list, saves title, note and tags, and deletes into the trash', function (): void {
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'bon.jpg')->firstOrFail();
    $tag = Tag::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->admin->org_id)->firstOrFail();

    $this->actingAs($this->admin->user)->get('/documents/'.$document->id)->assertOk()
        ->assertSee('win-detail', false)->assertSee('Verlauf');
    // From the window system only the windows come back, without menu and frame (A73).
    $this->get('/documents/'.$document->id, ['X-DocHamster-Window' => '1'])->assertOk()
        ->assertSee('data-window-url', false)->assertDontSee('nav-toggle', false);

    $this->post('/documents/'.$document->id, ['title' => 'Tankquittung', 'note' => 'Fahrt nach Köln', 'tags' => [$tag->id]])->assertRedirect();
    $document->refresh();
    expect([$document->title, $document->note, $document->tags()->pluck('tags.id')->all()])->toBe(['Tankquittung', 'Fahrt nach Köln', [$tag->id]]);

    // Note and recognised text are edited in place (points 27/28); the form keeps the note when it doesn't send it.
    $this->postJson('/documents/'.$document->id.'/field', ['field' => 'ocr_text', 'value' => 'SUMME 12,00'])->assertOk()->assertJson(['value' => 'SUMME 12,00']);
    // The pencil of a document row renames it in place (A174); a document always keeps a name, other fields stay closed.
    $this->postJson('/documents/'.$document->id.'/field', ['field' => 'title', 'value' => 'Neuer Name'])->assertOk()->assertJson(['value' => 'Neuer Name']);
    expect($document->fresh()?->title)->toBe('Neuer Name');
    $this->postJson('/documents/'.$document->id.'/field', ['field' => 'title', 'value' => '  '])->assertUnprocessable();
    $this->postJson('/documents/'.$document->id.'/field', ['field' => 'uploaded_by', 'value' => 'x'])->assertUnprocessable();
    $this->post('/documents/'.$document->id, ['title' => 'Tankquittung'])->assertRedirect();
    expect($document->refresh()->only(['note', 'ocr_text']))->toBe(['note' => 'Fahrt nach Köln', 'ocr_text' => 'SUMME 12,00']);

    $this->post('/documents/'.$document->id.'/delete')->assertRedirect(route('documents.index'));
    expect(Document::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0)
        ->and(Document::withTrashed()->withoutGlobalScope(OrgScope::class)->count())->toBe(1);
});

it('colours tags: a palette colour by chance, any colour by hand, and serves them as a stylesheet', function (): void {
    $this->actingAs($this->admin->user)->post('/tags', ['name' => 'Reise'])->assertRedirect();
    $travel = Tag::query()->where('name', 'Reise')->firstOrFail();
    expect(PaletteColor::DEFAULTS)->toContain($travel->color);

    $this->post('/tags/'.$travel->id, ['name' => 'Reise', 'color' => '#ff7622', 'color_hex' => '#12345A'])->assertRedirect();
    expect($travel->fresh()->color)->toBe('#12345a');
    $this->post('/tags/'.$travel->id, ['name' => 'Reise', 'color_hex' => 'red;}'])->assertSessionHasErrors('color');

    $this->get('/tags.css')->assertOk()->assertSee('.tag-'.$travel->id.'{--tag:#12345a;--tag-text:#ffffff}', false);
});
