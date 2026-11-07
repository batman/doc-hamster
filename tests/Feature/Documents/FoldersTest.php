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

it('gives every organisation the folder "Belege", with bank matching on', function (): void {
    $org = Organization::factory()->create();

    $belege = belegeOf($org->id);

    expect($belege->name)->toBe('Belege')
        ->and($belege->bank_matching)->toBeTrue()
        ->and($belege->parent_id)->toBeNull()
        ->and(Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org->id)->count())->toBe(1);
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
    $this->get('/folders')->assertOk()->assertSee('Verträge')->assertSee('Miete');
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

    expect(Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->orgId)->count())->toBe(3);
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

it('files an upload into a folder and shows a folder with everything below it', function (): void {
    $top = makeFolder($this->orgId, 'Steuer');
    $sub = makeFolder($this->orgId, 'Quittungen', $top->id);
    $this->actingAs($this->admin->user);

    $this->post('/documents', ['file' => UploadedFile::fake()->image('oben.jpg'), 'folder' => $top->id])->assertRedirect('/documents');
    $this->post('/documents', ['file' => UploadedFile::fake()->image('unten.jpg'), 'folder' => $sub->id])->assertRedirect('/documents');
    $this->post('/documents', ['file' => UploadedFile::fake()->image('lose.jpg')])->assertRedirect('/documents');

    $this->get('/documents?folder='.$top->id)->assertSee('oben.jpg')->assertSee('unten.jpg')->assertDontSee('lose.jpg');
    $this->get('/documents?folder='.$sub->id)->assertDontSee('oben.jpg')->assertSee('unten.jpg');
    $this->get('/documents?folder='.belegeOf($this->orgId)->id)->assertSee('lose.jpg')->assertDontSee('oben.jpg');
    $this->get('/documents')->assertSee('oben.jpg')->assertSee('lose.jpg');
});

it('moves a receipt to another folder from its detail view; an empty choice means "Belege"', function (): void {
    $folder = makeFolder($this->orgId, 'Verträge');
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('v.jpg')]);
    $document = titled('v.jpg');

    $this->post('/documents/'.$document->id, ['folder' => $folder->id])->assertRedirect();
    expect(titled('v.jpg')->folder_id)->toBe($folder->id);

    $this->post('/documents/'.$document->id, ['title' => 'v.jpg', 'folder' => ''])->assertRedirect();
    expect(titled('v.jpg')->folder_id)->toBe(belegeOf($this->orgId)->id);
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

    $this->post('/folders/'.$middle->id.'/delete')->assertRedirect('/folders');

    expect(folderRow($middle->id))->toBeNull()
        ->and(folderRow($leaf->id)?->parent_id)->toBe($top->id)
        ->and(titled('m.jpg')->folder_id)->toBe($top->id);

    $this->post('/folders/'.$top->id.'/delete')->assertRedirect('/folders');
    expect(titled('m.jpg')->folder_id)->toBe(belegeOf($this->orgId)->id)
        ->and(folderRow($leaf->id)?->parent_id)->toBeNull();
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

    $this->get('/folders?folder='.$foreign->id)->assertOk()->assertDontSee('Fremd');
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
    $this->actingAs($editor->user)->get('/folders')->assertOk();
});
