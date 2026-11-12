<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Points 102-106: search in the current folder or all folders, the recognised text, a result list with folders.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    $make = fn (string $name): Folder => Folder::withoutEvents(fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'name' => $name]));
    $this->a = $make('Reisen');
    $this->b = $make('Tanken');
    $doc = fn (string $title, Folder $folder, ?string $ocr = null) => Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title, 'folder_id' => $folder->id])
        ->forceFill(['ocr_text' => $ocr])->save();
    $doc('hotel-rechnung.pdf', $this->a, 'Zimmer 12 SUMME 80,00');
    $doc('tank-rechnung.pdf', $this->b, 'Diesel 40 Liter');
});

it('searches the current folder, or all folders with the checkbox, and shows each result with its folder', function (): void {
    $this->get('/documents?folder='.$this->a->id.'&q=rechnung')->assertOk()
        ->assertSee('hotel-rechnung.pdf')->assertDontSee('tank-rechnung.pdf')
        ->assertSee('1 Treffer')->assertSee('class="doc-folder"', false)->assertDontSee('data-folder-row', false);

    $this->get('/documents?folder='.$this->a->id.'&q=rechnung&all=1')
        ->assertSee('hotel-rechnung.pdf')->assertSee('tank-rechnung.pdf')->assertSee('2 Treffer');
});

it('looks for recognised text only in the panel field, not in the top field', function (): void {
    $this->get('/documents?q=Diesel')->assertSee('Nichts gefunden.')->assertDontSee('tank-rechnung.pdf');
    $this->get('/documents?ocr=Diesel')->assertSee('tank-rechnung.pdf')->assertDontSee('hotel-rechnung.pdf')
        // the window keeps the panel's fields out of its hidden fields and marks the panel as in use
        ->assertSee('name="ocr" value="Diesel"', false)->assertDontSee('type="hidden" name="ocr"', false);
});

it('shows the result list also in a timeline window and drops back to it without the search', function (): void {
    $this->get('/documents?view=timeline&q=hotel')->assertSee('hotel-rechnung.pdf')->assertSee('class="view-details"', false);
    $this->get('/documents?view=timeline')->assertSee('class="timeline"', false)->assertSee('tank-rechnung.pdf');
});
