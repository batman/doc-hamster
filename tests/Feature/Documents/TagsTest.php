<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| #32: tags on receipts, picked at upload, filter in the list.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
});

function tagNamed(string $orgId, string $name): Tag
{
    return Tag::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('name', $name)->firstOrFail();
}

function documentNamed(string $title): Document
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->where('title', $title)->firstOrFail();
}

it('gives a new organisation the pre-installed tags', function (): void {
    $org = Organization::factory()->create();

    $names = Tag::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org->id)->orderBy('name')->pluck('name')->all();

    expect($names)->toBe(['Quittung', 'Rechnung', 'Vertrag']);
});

it('stores the tags picked at upload, which are optional', function (): void {
    $invoice = tagNamed($this->orgId, 'Rechnung');
    $contract = tagNamed($this->orgId, 'Vertrag');

    $this->actingAs($this->admin->user)->post('/documents', [
        'file' => UploadedFile::fake()->image('mit.jpg'),
        'tags' => [$invoice->id, $contract->id],
    ])->assertRedirect('/');
    $this->post('/documents', ['file' => UploadedFile::fake()->image('ohne.jpg')])->assertRedirect('/');

    expect(DB::table('document_tags')->where('document_id', documentNamed('mit.jpg')->id)->pluck('source')->all())->toBe(['user', 'user'])
        ->and(DB::table('document_tags')->where('document_id', documentNamed('ohne.jpg')->id)->count())->toBe(0);
});

it('refuses tags of other organisations and does not create new ones at upload', function (): void {
    $foreign = tagNamed(Organization::factory()->create()->id, 'Rechnung');

    $this->actingAs($this->admin->user)->post('/documents', [
        'file' => UploadedFile::fake()->image('bon.jpg'),
        'tags' => [$foreign->id],
    ])->assertSessionHasErrors('tags.0');
    $this->post('/documents', [
        'file' => UploadedFile::fake()->image('bon.jpg'),
        'tags' => ['Ganz neu'],
    ])->assertSessionHasErrors('tags.0');

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0)
        ->and(Tag::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Ganz neu')->exists())->toBeFalse();
});

it('filters the list, and several tags must all match', function (): void {
    $invoice = tagNamed($this->orgId, 'Rechnung');
    $contract = tagNamed($this->orgId, 'Vertrag');

    $this->actingAs($this->admin->user);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('beide.jpg'), 'tags' => [$invoice->id, $contract->id]]);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('eins.jpg'), 'tags' => [$invoice->id]]);

    $this->get('/?'.http_build_query(['tags' => [$invoice->id]]))->assertSee('beide.jpg')->assertSee('eins.jpg');
    $this->get('/?'.http_build_query(['tags' => [$invoice->id, $contract->id]]))->assertSee('beide.jpg')->assertDontSee('eins.jpg');
});

it('lets those who may edit a receipt change its tags, limited to what they can see', function (): void {
    $invoice = tagNamed($this->orgId, 'Rechnung');
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')]);
    $document = documentNamed('bon.jpg');

    $this->post('/documents/'.$document->id.'/tags', ['tags' => [$invoice->id]])->assertRedirect();
    expect(DB::table('document_tags')->where('document_id', $document->id)->pluck('tag_id')->all())->toBe([$invoice->id]);

    $ownOnly = Membership::factory()->withRights(RoleRight::Edit)->create(['org_id' => $this->orgId]);
    $this->actingAs($ownOnly->user)->post('/documents/'.$document->id.'/tags', ['tags' => []])->assertNotFound();

    $advisor = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $this->actingAs($advisor->user)->post('/documents/'.$document->id.'/tags', ['tags' => []])->assertForbidden();
    $this->get('/?'.http_build_query(['tags' => [$invoice->id]]))->assertOk()->assertSee('bon.jpg');

    expect(DB::table('document_tags')->where('document_id', $document->id)->count())->toBe(1);
});

it('lets Admins and the checkbox manage the list, nobody else', function (): void {
    $this->actingAs($this->admin->user)->post('/tags', ['name' => 'Tanken'])->assertRedirect(route('tags.index'));
    expect(tagNamed($this->orgId, 'Tanken')->org_id)->toBe($this->orgId);

    $this->post('/tags', ['name' => 'tanken'])->assertSessionHasErrors('name');

    $manager = Membership::factory()->withRights(RoleRight::Tags)->create(['org_id' => $this->orgId]);
    $this->actingAs($manager->user)->post('/tags/'.tagNamed($this->orgId, 'Tanken')->id, ['name' => 'Sprit'])->assertRedirect();
    expect(tagNamed($this->orgId, 'Sprit')->name)->toBe('Sprit');

    $other = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $this->actingAs($other->user)->get('/tags')->assertForbidden();
    $this->post('/tags', ['name' => 'Nein'])->assertForbidden();
});

it('names the count before deleting a tag in use and keeps the receipts', function (): void {
    $invoice = tagNamed($this->orgId, 'Rechnung');
    $this->actingAs($this->admin->user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg'), 'tags' => [$invoice->id]]);

    $this->post('/tags/'.$invoice->id.'/delete')->assertRedirect(route('tags.index'))->assertSessionHas('confirm_delete');
    expect($invoice->fresh())->not->toBeNull();

    $this->post('/tags/'.$invoice->id.'/delete', ['confirmed' => 1])->assertRedirect(route('tags.index'));

    expect($invoice->fresh())->toBeNull()
        ->and(documentNamed('bon.jpg')->fresh())->not->toBeNull()
        ->and(DB::table('document_tags')->count())->toBe(0);
});

it('deletes an unused tag at once, and leaves other organisations alone', function (): void {
    $unused = tagNamed($this->orgId, 'Vertrag');
    $foreign = tagNamed(Organization::factory()->create()->id, 'Vertrag');

    $this->actingAs($this->admin->user);
    $this->post('/tags/'.$foreign->id.'/delete', ['confirmed' => 1])->assertNotFound();
    $this->post('/tags/'.$unused->id.'/delete')->assertRedirect(route('tags.index'))->assertSessionMissing('confirm_delete');

    expect($unused->fresh())->toBeNull();
});

it('lists the tags in the index of the export', function (): void {
    $user = User::factory()->create(['password_hash' => 'ein-langes-test-passwort']);
    $member = Membership::factory()->create(['user_id' => $user->id]);
    $invoice = tagNamed($member->org_id, 'Rechnung');
    $contract = tagNamed($member->org_id, 'Vertrag');

    $this->actingAs($user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg'), 'tags' => [$contract->id, $invoice->id]]);

    $today = today()->format('Y-m-d');
    $path = $this->post('/export', ['from' => $today, 'to' => $today, 'password' => 'ein-langes-test-passwort'])
        ->assertOk()->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    $zip->open($path);
    $index = (string) $zip->getFromName('index.csv');
    $zip->close();
    unlink($path);

    expect($index)->toContain('Rechnung, Vertrag');
});

it('gives existing organisations the pre-installed tags in the update', function (): void {
    $migration = require database_path('migrations/2026_10_31_110000_create_tags_tables.php');

    // Only the data step: schema changes would end the test's transaction on MariaDB/MySQL.
    DB::table('tags')->delete();
    $migration->addTagsToExistingOrganizations();

    expect(DB::table('tags')->where('org_id', $this->orgId)->count())->toBe(3);
});
