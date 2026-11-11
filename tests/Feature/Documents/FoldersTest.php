<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Issue #77: folders for receipts – the automatic folder "Belege", create, nest,
| move, delete, file into, filter by, and the bank matching switch per folder.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
});

function makeFolder(string $orgId, string $name, ?string $parentId = null, bool $bankMatching = false): Folder
{
    return Folder::withoutEvents(static fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $orgId, 'name' => $name, 'parent_id' => $parentId, 'bank_matching' => $bankMatching]));
}

function folderNamed(string $name): Folder
{
    return Folder::query()->withoutGlobalScope(OrgScope::class)->where('name', $name)->firstOrFail();
}

function folderRow(string $id): ?Folder
{
    return Folder::query()->withoutGlobalScope(OrgScope::class)->find($id);
}

function titled(string $title): Document
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->where('title', $title)->firstOrFail();
}

function belegeOf(string $orgId): Folder
{
    return Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('is_default', true)->firstOrFail();
}

it('gives every organisation "Dokumente allgemein" and "Rechnungen", with bank matching on', function (): void {
    $org = Organization::factory()->create();

    $belege = belegeOf($org->id);
    $invoices = Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org->id)->where('name', 'Rechnungen')->firstOrFail();

    expect($belege->name)->toBe('Dokumente allgemein')
        ->and($belege->bank_matching)->toBeTrue()
        ->and($belege->parent_id)->toBeNull()
        ->and($invoices->is_default)->toBeFalse()
        ->and($invoices->bank_matching)->toBeTrue()
        ->and($invoices->iconName())->toBe('file-invoice')
        ->and(Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org->id)->count())->toBe(2);
});

it('puts a receipt without a chosen folder into "Belege"', function (): void {
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('lose.jpg')])->assertRedirect('/documents');

    expect(titled('lose.jpg')->folder_id)->toBe(belegeOf($this->orgId)->id);
});

it('creates a folder and one inside it; new folders have bank matching off unless ticked', function (): void {
    $this->actingAs($this->admin->user)->post('/folders', ['name' => 'Verträge'])->assertRedirect();
    $top = folderNamed('Verträge');

    $this->post('/folders', ['name' => 'Miete', 'parent_id' => $top->id, 'bank_matching' => '1'])->assertRedirect();

    expect($top->bank_matching)->toBeFalse()
        ->and(folderNamed('Miete')->parent_id)->toBe($top->id)
        ->and(folderNamed('Miete')->bank_matching)->toBeTrue()
        ->and($top->org_id)->toBe($this->orgId);
    $this->get('/documents')->assertOk()->assertSee('Verträge');
    $this->get('/documents?folder='.$top->id)->assertOk()->assertSee('Miete');
});

it('switches bank matching on and off for any folder, "Belege" included', function (): void {
    $folder = makeFolder($this->orgId, 'Archiv');
    $belege = belegeOf($this->orgId);
    $this->actingAs($this->admin->user);

    $this->post('/folders/'.$folder->id, ['name' => 'Archiv', 'bank_matching' => '1'])->assertRedirect();
    $this->post('/folders/'.$belege->id, ['name' => 'Belege'])->assertRedirect();

    expect(folderRow($folder->id)?->bank_matching)->toBeTrue()
        ->and(folderRow($belege->id)?->bank_matching)->toBeFalse();
});

it('allows one name per level only, compared without case', function (): void {
    $top = makeFolder($this->orgId, 'Privat');
    $this->actingAs($this->admin->user);

    $this->post('/folders', ['name' => 'privat'])->assertSessionHasErrors('name');
    $this->post('/folders', ['name' => 'Privat', 'parent_id' => $top->id])->assertSessionDoesntHaveErrors();
    $this->post('/folders', ['name' => 'a/b'])->assertSessionHasErrors('name');

    expect(Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->orgId)->count())->toBe(4);
});

it('does not move a folder into itself, below itself or too deep, and keeps "Belege" on top', function (): void {
    $a = makeFolder($this->orgId, 'A');
    $b = makeFolder($this->orgId, 'B', $a->id);
    $this->actingAs($this->admin->user);

    $this->post('/folders/'.$a->id, ['name' => 'A', 'parent_id' => $a->id])->assertSessionHasErrors('parent_id');
    $this->post('/folders/'.$a->id, ['name' => 'A', 'parent_id' => $b->id])->assertSessionHasErrors('parent_id');

    $parent = $b;
    foreach (range(1, Folder::MAX_DEPTH - 1) as $level) {
        $parent = makeFolder($this->orgId, 'Ebene '.$level, $parent->id);
    }
    $this->post('/folders', ['name' => 'zu tief', 'parent_id' => $parent->id])->assertSessionHasErrors('parent_id');
    expect(Folder::query()->withoutGlobalScope(OrgScope::class)->where('name', 'zu tief')->exists())->toBeFalse();

    $belege = belegeOf($this->orgId);
    $this->post('/folders/'.$belege->id, ['name' => 'Belege', 'parent_id' => $a->id])->assertRedirect();
    expect(folderRow($belege->id)?->parent_id)->toBeNull();
});

it('files an upload into a folder; a folder shows its own receipts, a search everything below', function (): void {
    $top = makeFolder($this->orgId, 'Steuer');
    $sub = makeFolder($this->orgId, 'Quittungen', $top->id);
    $this->actingAs($this->admin->user);

    $this->post('/documents', ['file' => UploadedFile::fake()->image('oben.jpg'), 'folder' => $top->id])->assertRedirect('/documents');
    $this->post('/documents', ['file' => UploadedFile::fake()->image('unten.jpg'), 'folder' => $sub->id])->assertRedirect('/documents');
    $this->post('/documents', ['file' => UploadedFile::fake()->image('lose.jpg')])->assertRedirect('/documents');

    $this->get('/documents?folder='.$top->id)->assertSee('oben.jpg')->assertDontSee('unten.jpg')->assertDontSee('lose.jpg');
    $this->get('/documents?folder='.$top->id.'&q=.jpg')->assertSee('oben.jpg')->assertSee('unten.jpg')->assertDontSee('lose.jpg');
    $this->get('/documents?folder='.$sub->id)->assertDontSee('oben.jpg')->assertSee('unten.jpg');
    $this->get('/documents?folder='.belegeOf($this->orgId)->id)->assertSee('lose.jpg')->assertDontSee('oben.jpg');
    $this->get('/documents?q=.jpg')->assertSee('oben.jpg')->assertSee('lose.jpg');
});

it('moves a receipt to another folder from its detail view; an empty choice means "Belege"', function (): void {
    $folder = makeFolder($this->orgId, 'Verträge');
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('v.jpg')]);
    $document = titled('v.jpg');

    $this->post('/documents/'.$document->id, ['folder' => $folder->id])->assertRedirect();
    expect(titled('v.jpg')->folder_id)->toBe($folder->id);

    $this->post('/documents/'.$document->id, ['title' => 'v.jpg', 'folder' => ''])->assertRedirect();
    expect(titled('v.jpg')->folder_id)->toBe(belegeOf($this->orgId)->id);

    // "In Ordner verschieben" from the ⋯ menu changes only the folder (point 26).
    $this->post('/documents/'.$document->id.'/move', ['folder' => $folder->id])->assertRedirect();
    expect(titled('v.jpg')->folder_id)->toBe($folder->id);
    $this->post('/documents/'.$document->id.'/move', ['folder' => ''])->assertSessionHasErrors('folder');
});

it('keeps the folder when a form without the folder field saves the receipt', function (): void {
    $folder = makeFolder($this->orgId, 'Bleibt');
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('b.jpg'), 'folder' => $folder->id]);

    $this->post('/documents/'.titled('b.jpg')->id, ['note' => 'nur eine Notiz'])->assertRedirect();

    expect(titled('b.jpg')->folder_id)->toBe($folder->id);
});

it('moves receipts and subfolders up when a folder is deleted; a top folder hands its receipts to "Belege"', function (): void {
    $top = makeFolder($this->orgId, 'Oben');
    $middle = makeFolder($this->orgId, 'Mitte', $top->id);
    $leaf = makeFolder($this->orgId, 'Unten', $middle->id);
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('m.jpg'), 'folder' => $middle->id]);

    $this->post('/folders/'.$middle->id.'/delete')->assertRedirect('/documents?folder='.$top->id);

    expect(folderRow($middle->id))->toBeNull()
        ->and(folderRow($leaf->id)?->parent_id)->toBe($top->id)
        ->and(titled('m.jpg')->folder_id)->toBe($top->id);

    $this->post('/folders/'.$top->id.'/delete')->assertRedirect('/documents');
    expect(titled('m.jpg')->folder_id)->toBe(belegeOf($this->orgId)->id)
        ->and(folderRow($leaf->id)?->parent_id)->toBeNull();
});

it('deletes a folder with its content: subfolders go, receipts land in the Papierkorb', function (): void {
    $top = makeFolder($this->orgId, 'Oben');
    $middle = makeFolder($this->orgId, 'Mitte', $top->id);
    $leaf = makeFolder($this->orgId, 'Unten', $middle->id);
    $sibling = makeFolder($this->orgId, 'Daneben', $top->id);
    $this->actingAs($this->admin->user);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('mitte.jpg'), 'folder' => $middle->id]);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('unten.jpg'), 'folder' => $leaf->id]);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('daneben.jpg'), 'folder' => $sibling->id]);

    $this->post('/folders/'.$middle->id.'/delete', ['with_content' => '1'])->assertRedirect('/documents?folder='.$top->id);

    $inTrash = static fn (string $title): Document => Document::query()->withoutGlobalScope(OrgScope::class)->withTrashed()->where('title', $title)->firstOrFail();
    expect(folderRow($middle->id))->toBeNull()
        ->and(folderRow($leaf->id))->toBeNull()
        ->and(folderRow($sibling->id))->not->toBeNull()
        ->and($inTrash('mitte.jpg')->trashed())->toBeTrue()
        ->and($inTrash('unten.jpg')->trashed())->toBeTrue()
        // Brought back from the Papierkorb, they land in the folder above.
        ->and($inTrash('unten.jpg')->folder_id)->toBe($top->id)
        ->and(titled('daneben.jpg')->folder_id)->toBe($sibling->id);
});

it('deletes with content only for people who may put receipts into the Papierkorb', function (): void {
    $folder = makeFolder($this->orgId, 'Geschützt');
    $editor = Membership::factory()->withRights(RoleRight::Edit)->create(['org_id' => $this->orgId]);

    $this->actingAs($editor->user)->post('/folders/'.$folder->id.'/delete', ['with_content' => '1'])->assertForbidden();

    expect(folderRow($folder->id))->not->toBeNull();
});

it('cannot delete the folder "Belege"', function (): void {
    $belege = belegeOf($this->orgId);

    $this->actingAs($this->admin->user)->post('/folders/'.$belege->id.'/delete')->assertSessionHasErrors('folder');

    expect(folderRow($belege->id))->not->toBeNull();
});

it('never shows, uses or changes folders of another organisation', function (): void {
    $other = Organization::factory()->create();
    $foreign = makeFolder($other->id, 'Fremd');
    $this->actingAs($this->admin->user);

    $this->get('/folders?folder='.$foreign->id)->assertRedirect('/documents');
    $this->get('/documents?folder='.$foreign->id)->assertOk()->assertDontSee('Fremd');
    $this->post('/folders/'.$foreign->id, ['name' => 'Gekapert'])->assertNotFound();
    $this->post('/folders/'.$foreign->id.'/delete')->assertNotFound();
    $this->post('/folders', ['name' => 'Eigen', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id');
    $this->post('/documents', ['file' => UploadedFile::fake()->image('x.jpg'), 'folder' => $foreign->id])->assertSessionHasErrors('folder');

    expect(folderRow($foreign->id)?->name)->toBe('Fremd');
});

it('keeps folder management to people who may edit receipts', function (): void {
    $viewer = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $editor = Membership::factory()->withRights(RoleRight::Edit)->create(['org_id' => $this->orgId]);

    $this->actingAs($viewer->user)->get('/folders')->assertForbidden();
    $this->post('/folders', ['name' => 'Heimlich'])->assertForbidden();
    $this->actingAs($editor->user)->get('/folders?new=1')->assertOk();
});

it('keeps the name of "Dokumente allgemein"; "Rechnungen" may be renamed and deleted', function (): void {
    $belege = belegeOf($this->orgId);
    $invoices = folderNamed('Rechnungen');
    $this->actingAs($this->admin->user);

    $this->post('/folders/'.$belege->id, ['name' => 'Anders', 'look' => '1', 'icon' => 'star'])->assertRedirect();
    $this->post('/folders/'.$invoices->id, ['name' => 'Eingangsrechnungen'])->assertRedirect();

    expect(folderRow($belege->id)?->name)->toBe('Dokumente allgemein')
        ->and(folderRow($belege->id)?->icon)->toBe('star')
        ->and(folderRow($invoices->id)?->name)->toBe('Eingangsrechnungen');

    $this->post('/folders/'.$invoices->id.'/delete')->assertRedirect('/documents');
    expect(folderRow($invoices->id))->toBeNull();
});

it('gives a folder a colour and a symbol from the fixed set, and serves the colour as CSS', function (): void {
    $this->actingAs($this->admin->user);

    $this->post('/folders', ['name' => 'Auto', 'look' => '1', 'color' => '#3fb68f', 'icon' => 'car'])->assertSessionDoesntHaveErrors();
    $auto = folderNamed('Auto');
    $this->post('/folders', ['name' => 'Eigen', 'look' => '1', 'color' => 'none', 'color_hex' => '#12AB34'])->assertSessionDoesntHaveErrors();

    expect($auto->color)->toBe('#3fb68f')
        ->and($auto->icon)->toBe('car')
        ->and(folderNamed('Eigen')->color)->toBe('#12ab34');
    $this->get('/tags.css')->assertOk()->assertSee('.fc-'.$auto->id.'{--folder:#3fb68f}', false);

    $this->post('/folders', ['name' => 'Böse', 'look' => '1', 'icon' => 'skull'])->assertSessionHasErrors('icon');
    $this->post('/folders', ['name' => 'Böse', 'look' => '1', 'color_hex' => 'red;}body{x'])->assertSessionHasErrors('color');

    // A form without the look fields (the phone's rename) keeps colour and symbol.
    $this->post('/folders/'.$auto->id, ['name' => 'Autos'])->assertRedirect();
    expect(folderRow($auto->id)?->icon)->toBe('car')->and(folderRow($auto->id)?->color)->toBe('#3fb68f');
});

it('shows the folders as sortable rows in the Dokumente window, not in the main menu; a folder shows its subfolders', function (): void {
    $invoices = folderNamed('Rechnungen');
    $phone = makeFolder($this->orgId, 'Telefon', $invoices->id);
    $this->actingAs($this->admin->user);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('tel.jpg'), 'folder' => $phone->id]);

    $this->get('/documents')->assertOk()
        ->assertDontSee('nav-folders', false)
        ->assertSee('data-sortable', false)
        ->assertSee('data-id="trash"', false)
        ->assertSeeInOrder(['Dokumente allgemein', 'Rechnungen', 'Kein Dokument', 'Papierkorb']);
    $this->get('/documents?folder='.$invoices->id)->assertOk()
        ->assertSee('data-window-upload="folder='.$invoices->id.'"', false)
        ->assertSee('data-id="'.$phone->id.'"', false)
        ->assertSee('1 Dokument')
        ->assertDontSee('data-id="trash"', false)
        ->assertSee('Ordner anlegen');
    $this->get('/documents/trash')->assertOk()->assertSee('data-window-title="Papierkorb"', false);
});

it('saves the order of one level by drag and drop, the Papierkorb included', function (): void {
    $belege = belegeOf($this->orgId);
    $invoices = folderNamed('Rechnungen');
    $other = makeFolder($this->orgId, 'Zuerst');
    $this->actingAs($this->admin->user);

    $this->postJson('/folders/order', ['parent_id' => '', 'order' => [$other->id, 'trash', $invoices->id, $belege->id]])->assertOk();

    expect(folderRow($other->id)?->position)->toBe(0)
        ->and(folderRow($belege->id)?->position)->toBe(2)
        ->and(data_get(Organization::query()->findOrFail($this->orgId)->settings_json, 'folders.trash_slot'))->toBe(1);
    $this->get('/documents')->assertSeeInOrder(['Zuerst', 'Papierkorb', 'Rechnungen', 'Dokumente allgemein']);

    $sub = makeFolder($this->orgId, 'Unter', $other->id);
    $this->postJson('/folders/order', ['parent_id' => $other->id, 'order' => [$sub->id, 'trash']])->assertStatus(422);
    $this->postJson('/folders/order', ['parent_id' => '', 'order' => [$sub->id]])->assertStatus(422);
});

it('imports a folder as a new folder with the same name, subfolders and all', function (): void {
    $this->actingAs($this->admin->user);

    $start = $this->postJson('/folders/import', ['name' => 'Steuer 2025'])->assertCreated();
    $root = folderRow((string) $start->json('id'));
    expect($root?->name)->toBe('Steuer 2025')->and($root?->parent_id)->toBeNull();
    $url = '/folders/import/'.$root?->id.'/file';

    $this->post($url, ['file' => UploadedFile::fake()->image('a.jpg'), 'path' => 'Tanken/Mai/a.jpg'], ['Accept' => 'application/json'])->assertCreated();
    $this->post($url, ['file' => UploadedFile::fake()->image('b.jpg'), 'path' => 'b.jpg'], ['Accept' => 'application/json'])->assertCreated();
    $this->post($url, ['file' => UploadedFile::fake()->image('c.jpg'), 'path' => 'Tanken/c.jpg'], ['Accept' => 'application/json'])->assertCreated();
    $this->post($url, ['file' => UploadedFile::fake()->create('notiz.txt', 1, 'text/plain'), 'path' => 'notiz.txt'], ['Accept' => 'application/json'])->assertStatus(422);

    $tanken = Folder::query()->withoutGlobalScope(OrgScope::class)->where('parent_id', $root?->id)->where('name', 'Tanken')->firstOrFail();
    $mai = Folder::query()->withoutGlobalScope(OrgScope::class)->where('parent_id', $tanken->id)->where('name', 'Mai')->firstOrFail();
    expect(titled('a.jpg')->folder_id)->toBe($mai->id)
        ->and(titled('b.jpg')->folder_id)->toBe($root?->id)
        ->and(titled('c.jpg')->folder_id)->toBe($tanken->id)
        ->and(Folder::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Tanken')->count())->toBe(1);

    // The same folder again: a second folder "Steuer 2025 (2)", the first stays as it is.
    expect($this->postJson('/folders/import', ['name' => 'Steuer 2025'])->assertCreated()->json('name'))->toBe('Steuer 2025 (2)');
});

it('keeps the folder import to people who may upload and edit, and to the own organisation', function (): void {
    $viewer = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $foreign = makeFolder(Organization::factory()->create()->id, 'Fremd');

    $this->actingAs($viewer->user)->postJson('/folders/import', ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->admin->user)
        ->post('/folders/import/'.$foreign->id.'/file', ['file' => UploadedFile::fake()->image('x.jpg'), 'path' => 'x.jpg'], ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('shows folders as windows: the list as root, a folder or a new one to its right with ids that carry the folder', function (): void {
    $this->actingAs($this->admin->user);
    $travel = makeFolder($this->orgId, 'Reisen');

    $this->get('/folders')->assertRedirect('/documents');
    $this->get('/folders?new=1')->assertOk()->assertSee('Neuen Ordner anlegen')->assertSee('data-folder-import', false)->assertSee('id="name-new"', false);
    $this->get('/folders?parent='.$travel->id)->assertOk()->assertSee('data-folder-import', false);
    $this->get('/folders?folder='.$travel->id)->assertOk()
        ->assertSee('data-dialog="delete-folder-'.$travel->id.'"', false)
        ->assertSee('id="name-'.$travel->id.'"', false)
        ->assertSee('data-window-url="'.route('folders.index', ['folder' => $travel->id]).'"', false);
    $this->get('/documents?folder='.$travel->id)->assertOk()->assertSee('data-window-title="Reisen"', false);
});
