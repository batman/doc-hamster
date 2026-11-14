<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Tag;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\PropertyValues;
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

it('looks in the name and the recognised text by default, elsewhere only when "Suchen in" says so (187.1)', function (): void {
    $this->get('/documents?q=Diesel')->assertSee('tank-rechnung.pdf')->assertDontSee('hotel-rechnung.pdf')
        // the default chips are ticked and none is a hidden field of the window
        ->assertSee('name="in[]" value="ocr" checked', false)->assertDontSee('name="in[]" value="note" checked', false)
        ->assertDontSee('type="hidden" name="in[]"', false);
    $this->get('/documents?q=Diesel&in[]=name')->assertSee(__('empty.search'));
    // the old address keeps working: ocr=1 adds the recognised text
    $this->get('/documents?q=Diesel&in[]=name&ocr=1')->assertSee('tank-rechnung.pdf');
    // unknown fields are ignored, nothing left = the default
    $this->get('/documents?q=Diesel&in[]=bogus')->assertSee('tank-rechnung.pdf');
});

it('searches the note, tag names and property values only with their chip (187.1)', function (): void {
    app(CurrentOrg::class)->set($this->admin);
    $hotel = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'hotel-rechnung.pdf')->firstOrFail();
    $hotel->forceFill(['note' => 'Messe Hannover'])->save();
    $this->post('/tags', ['name' => 'Dienstreise'])->assertRedirect();
    $hotel->tags()->attach(Tag::query()->where('name', 'Dienstreise')->firstOrFail());
    app(PropertyValues::class)->set($hotel, 'sender', 'Musterhotel GmbH');

    foreach (['note' => 'Hannover', 'tags' => 'Dienstreise', 'props' => 'Musterhotel'] as $field => $term) {
        $this->get('/documents?q='.$term.'&all=1')->assertSee(__('empty.search'));
        $this->get('/documents?q='.$term.'&all=1&in[]='.$field)->assertSee('hotel-rechnung.pdf')->assertDontSee('tank-rechnung.pdf')
            ->assertSee('name="in[]" value="'.$field.'" checked', false);
    }
});

it('shows the result list also in a timeline window and drops back to it without the search', function (): void {
    $this->get('/documents?view=timeline&q=hotel')->assertSee('hotel-rechnung.pdf')->assertSee('class="view-details"', false);
    $this->get('/documents?view=timeline')->assertSee('class="timeline"', false)->assertSee('tank-rechnung.pdf');
});
