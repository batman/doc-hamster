<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Points 75-87: drag and drop of documents and folders, several at once, the
| Papierkorb as a folder (rename, colour, delete for good, empty), uploads by drop.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
    $this->actingAs($this->admin->user);
});

function dndFolder(string $orgId, string $name, ?string $parentId = null): Folder
{
    return Folder::withoutEvents(static fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $orgId, 'name' => $name, 'parent_id' => $parentId]));
}

function dndDoc(string $title): Document
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->withTrashed()->where('title', $title)->firstOrFail();
}

function dndUpload(object $test, string $name, ?string $folder = null): Document
{
    $test->post('/documents', ['file' => UploadedFile::fake()->image($name), 'folder' => $folder]);

    return dndDoc($name);
}

it('moves several documents onto a folder, says where they were for "Rückgängig" and logs each move', function (): void {
    $target = dndFolder($this->orgId, 'Steuer');
    $from = dndFolder($this->orgId, 'Alt');
    $a = dndUpload($this, 'a.jpg', $from->id);
    $b = dndUpload($this, 'b.jpg');

    $this->postJson('/documents/bulk/move', ['ids' => [$a->id, $b->id], 'folder' => $target->id])->assertOk()
        ->assertJson(['message' => '2 Dokumente nach „Steuer“ verschoben'])
        ->assertJsonFragment(['id' => $a->id, 'folder' => $from->id, 'trashed' => false]);

    expect(dndDoc('a.jpg')->folder_id)->toBe($target->id)
        ->and(dndDoc('b.jpg')->folder_id)->toBe($target->id)
        ->and(DB::table('audit_log')->where('action', 'document.moved')->count())->toBe(2);

    // "Rückgängig" moves it back.
    $this->postJson('/documents/bulk/move', ['ids' => [$a->id], 'folder' => $from->id])->assertOk();
    expect(dndDoc('a.jpg')->folder_id)->toBe($from->id);
    $this->postJson('/documents/bulk/move', ['ids' => [$a->id], 'folder' => 'nope'])->assertUnprocessable();
});

it('trashes documents dropped on the Papierkorb and brings them back; from the Papierkorb onto a folder restores into it', function (): void {
    $folder = dndFolder($this->orgId, 'Ziel');
    $doc = dndUpload($this, 't.jpg');

    $this->postJson('/documents/bulk/trash', ['ids' => [$doc->id]])->assertOk()->assertJson(['message' => '1 Dokument nach „Papierkorb“ verschoben']);
    expect(dndDoc('t.jpg')->trashed())->toBeTrue();

    $this->postJson('/documents/bulk/restore', ['ids' => [$doc->id]])->assertOk();
    expect(dndDoc('t.jpg')->trashed())->toBeFalse();

    $this->postJson('/documents/bulk/trash', ['ids' => [$doc->id]])->assertOk();
    $this->postJson('/documents/bulk/move', ['ids' => [$doc->id], 'folder' => $folder->id])->assertOk()
        ->assertJson(['message' => '1 Dokument nach „Ziel“ wiederhergestellt'])
        ->assertJsonFragment(['trashed' => true]);
    expect(dndDoc('t.jpg')->trashed())->toBeFalse()->and(dndDoc('t.jpg')->folder_id)->toBe($folder->id);
});

it('lets only people with the delete right trash, restore or delete for good', function (): void {
    $doc = dndUpload($this, 'r.jpg');
    $folder = dndFolder($this->orgId, 'Ziel');
    $this->postJson('/documents/bulk/trash', ['ids' => [$doc->id]])->assertOk();
    $editor = Membership::factory()->withRights(RoleRight::ViewAll, RoleRight::Edit)->create(['org_id' => $this->orgId]);

    $this->actingAs($editor->user);
    $this->postJson('/documents/bulk/trash', ['ids' => [$doc->id]])->assertForbidden();
    $this->postJson('/documents/bulk/purge', ['ids' => [$doc->id]])->assertForbidden();
    $this->postJson('/documents/trash/empty')->assertForbidden();
    // A trashed document can't be dragged out of the Papierkorb without the right either.
    $this->postJson('/documents/bulk/move', ['ids' => [$doc->id], 'folder' => $folder->id])->assertOk();

    expect(dndDoc('r.jpg')->trashed())->toBeTrue();
});

it('deletes for good only what is in the Papierkorb, files included, and logs it', function (): void {
    $gone = dndUpload($this, 'weg.jpg');
    $kept = dndUpload($this, 'bleibt.jpg');
    $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $gone->id)->firstOrFail();
    Storage::disk('local')->assertExists($file->path);
    $this->postJson('/documents/bulk/trash', ['ids' => [$gone->id]])->assertOk();

    $this->postJson('/documents/bulk/purge', ['ids' => [$gone->id, $kept->id]])->assertOk()->assertJson(['message' => '1 Dokument endgültig gelöscht']);

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->withTrashed()->find($gone->id))->toBeNull()
        ->and(dndDoc('bleibt.jpg')->trashed())->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'document.purged')->count())->toBe(1);
    Storage::disk('local')->assertMissing($file->path);
});

it('empties the Papierkorb after a confirm that names the count', function (): void {
    foreach (['x.jpg', 'y.jpg'] as $name) {
        $this->postJson('/documents/bulk/trash', ['ids' => [dndUpload($this, $name)->id]]);
    }

    $this->get('/documents/trash')->assertOk()->assertSee('2 Dokumente endgültig löschen? Das lässt sich nicht rückgängig machen.');
    $this->post('/documents/trash/empty')->assertRedirect('/documents/trash');

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->onlyTrashed()->count())->toBe(0);
});

it('moves a folder into another one, never into itself, below itself or too deep; "Dokumente allgemein" stays', function (): void {
    $a = dndFolder($this->orgId, 'A');
    $b = dndFolder($this->orgId, 'B');
    $a1 = dndFolder($this->orgId, 'A1', $a->id);

    $this->postJson('/folders/'.$b->id.'/move', ['parent_id' => $a->id])->assertOk()->assertJson(['message' => '„B“ liegt jetzt in „A“.']);
    expect(Folder::query()->find($b->id)?->parent_id)->toBe($a->id);

    $this->postJson('/folders/'.$a->id.'/move', ['parent_id' => $a1->id])->assertUnprocessable();
    $this->postJson('/folders/'.$a->id.'/move', ['parent_id' => $a->id])->assertUnprocessable();
    $this->postJson('/folders/'.Folder::defaultIdFor($this->orgId).'/move', ['parent_id' => $a->id])->assertUnprocessable();

    // Five levels at most.
    $chain = $a1;
    foreach (['L2', 'L3', 'L4'] as $name) {
        $chain = dndFolder($this->orgId, $name, $chain->id);
    }
    $deep = dndFolder($this->orgId, 'Tief');
    dndFolder($this->orgId, 'Tief2', $deep->id);
    $this->postJson('/folders/'.$deep->id.'/move', ['parent_id' => $chain->id])->assertUnprocessable();

    // Back to the top ("Rückgängig").
    $this->postJson('/folders/'.$b->id.'/move', ['parent_id' => ''])->assertOk();
    expect(Folder::query()->find($b->id)?->parent_id)->toBeNull();
});

it('drops a folder onto the Papierkorb with its content and moves it one step up or down from its properties', function (): void {
    $folder = dndFolder($this->orgId, 'Weg');
    $doc = dndUpload($this, 'drin.jpg', $folder->id);

    $this->postJson('/folders/'.$folder->id.'/delete', ['with_content' => 1])->assertOk()->assertJson(['message' => 'Der Ordner wurde gelöscht, 1 Dokument liegt im Papierkorb.']);
    expect(dndDoc('drin.jpg')->trashed())->toBeTrue()->and(Folder::query()->find($folder->id))->toBeNull();
    $this->postJson('/folders/'.Folder::defaultIdFor($this->orgId).'/delete', ['with_content' => 1])->assertUnprocessable();

    $first = dndFolder($this->orgId, 'Erster');
    $this->postJson('/folders/order', ['order' => [$first->id, Folder::defaultIdFor($this->orgId), 'trash']])->assertOk();
    $this->get('/folders?folder='.$first->id)->assertOk()->assertSee('Nach unten');
    $this->post('/folders/'.$first->id.'/step', ['direction' => 'down'])->assertRedirect();
    $this->get('/documents')->assertSeeInOrder(['Dokumente allgemein', 'Erster', 'Papierkorb']);
    unset($doc);
});

it('lets the Papierkorb be renamed and coloured; its properties have no move, symbol or bank matching', function (): void {
    $this->post('/folders/trash', ['name' => 'Mülleimer', 'look' => '1', 'color' => '#e53935'])->assertRedirect();

    $settings = Organization::query()->findOrFail($this->orgId)->settings_json;
    expect(data_get($settings, 'folders.trash_name'))->toBe('Mülleimer')->and(data_get($settings, 'folders.trash_color'))->toBe('#e53935');
    $this->get('/documents')->assertOk()->assertSee('Mülleimer')->assertSee('row-color c-e53935', false);
    $this->get('/documents/trash')->assertOk()->assertSee('data-window-title="Mülleimer"', false);
    $this->get('/folders?folder=trash')->assertOk()->assertSee('Mülleimer')->assertDontSee('name="bank_matching"', false)->assertDontSee('name="icon"', false)->assertDontSee('name="parent_id"', false);
    $this->get('/tags.css')->assertSee('.c-e53935{', false);

    $this->postJson('/folders/trash', ['name' => ''])->assertUnprocessable();
});

it('answers a dropped upload with the folder it went into', function (): void {
    $folder = dndFolder($this->orgId, 'Belege 2026');

    $this->postJson('/documents', ['files' => [UploadedFile::fake()->image('1.jpg'), UploadedFile::fake()->image('2.jpg')], 'folder' => $folder->id])->assertOk()
        ->assertJson(['message' => '2 Dokumente in „Belege 2026“ hochgeladen', 'folder' => $folder->id]);
    $this->postJson('/documents', ['files' => [UploadedFile::fake()->image('3.jpg')]])->assertOk()
        ->assertJson(['message' => '1 Dokument in „Dokumente allgemein“ hochgeladen']);
    $this->postJson('/documents', ['files' => [UploadedFile::fake()->create('x.txt', 1, 'text/plain')]])->assertUnprocessable();
});

it('draws folder rows with row actions instead of a ⋯ menu; only normal folders drag', function (): void {
    $folder = dndFolder($this->orgId, 'Normal');

    $this->get('/documents')->assertOk()
        ->assertSee('data-drag-folder', false)
        ->assertSee('data-folder-rename', false)
        ->assertSee('/folders?folder='.$folder->id, false)
        ->assertSee('/folders?folder=trash', false)
        ->assertDontSee('more-menu folder', false);
    $html = (string) $this->get('/documents')->getContent();
    preg_match('/<li[^>]*data-id="'.Folder::defaultIdFor($this->orgId).'"[^>]*>/', $html, $default);
    expect($default[0] ?? '')->not->toContain('data-drag-folder');
});
