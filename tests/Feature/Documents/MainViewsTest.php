<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Points 98-108: folder view, timeline and calendar as main views, the date basis, the calendar range.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    $this->folder = Folder::withoutEvents(fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'name' => 'Reisen']));
    $this->doc = function (string $title, string $uploaded, ?string $docDate = null, ?string $folderId = null): Document {
        $document = new Document(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title, 'doc_date' => $docDate, 'folder_id' => $folderId]);
        $document->forceFill(['created_at' => $uploaded])->save();

        return $document;
    };
});

it('shows every document in the timeline, whatever the folder, never the trash', function (): void {
    ($this->doc)('oben.jpg', '2026-03-01 10:00:00');
    ($this->doc)('im-ordner.jpg', '2026-03-02 10:00:00', null, $this->folder->id);
    ($this->doc)('weg.jpg', '2026-03-03 10:00:00')->delete();

    $this->get('/documents?view=timeline&folder='.$this->folder->id)->assertOk()
        ->assertSee('oben.jpg')->assertSee('im-ordner.jpg')->assertDontSee('weg.jpg')
        ->assertDontSee('data-folder-row', false)->assertSee('data-no-drop', false);
    // The folder view still shows only the folder's own documents.
    $this->get('/documents?view=details&folder='.$this->folder->id)->assertSee('im-ordner.jpg')->assertDontSee('oben.jpg');
});

it('pages the calendar only between the first and the last month, by the chosen date', function (): void {
    ($this->doc)('alt.jpg', '2025-11-20 10:00:00', '2025-01-15');
    ($this->doc)('neu.jpg', '2026-02-10 10:00:00');

    // Receipt date: January 2025 to February 2026; before and after are clamped.
    $this->get('/documents?view=calendar&month=2020-01')->assertSee('Januar 2025')->assertSee('alt.jpg');
    $this->get('/documents?view=calendar&month=2030-01')->assertSee('Februar 2026')->assertSee('neu.jpg');
    // Upload date: alt.jpg lies in November 2025, which is now the first month.
    $this->get('/documents?view=calendar&date=upload&month=2020-01')->assertSee('November 2025')->assertSee('alt.jpg');
});

it('remembers the date basis and the last folder display mode', function (): void {
    ($this->doc)('alt.jpg', '2026-05-01 10:00:00', '2024-06-01');

    $this->post('/documents/view', ['date' => 'upload', 'back' => url('/documents?view=timeline')])->assertRedirect('/documents?view=timeline&date=upload');
    $this->post('/documents/view', ['view' => 'small']);
    $this->post('/documents/view', ['view' => 'timeline']);
    $user = $this->admin->user->fresh();
    expect([$user->receipt_dates, $user->folder_view, $user->receipt_view])->toBe(['upload', 'small', 'timeline']);

    $this->get('/documents')->assertSee('Mai 2026')->assertDontSee('Juni 2024')
        ->assertSee('name="view" value="small" class="menu-item"', false);
    $this->get('/documents?date=doc')->assertSee('Juni 2024');
    $this->post('/documents/view', ['date' => 'tomorrow'])->assertSessionHasErrors('date');
});
