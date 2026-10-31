<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| MVP: upload, list, protected file delivery.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(fn () => Storage::fake('local'));

it('sends guests to the login', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('lets an owner upload a receipt, see it and open the file', function (): void {
    $owner = Membership::factory()->owner()->create();

    $this->actingAs($owner->user)
        ->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')])
        ->assertRedirect('/');

    $file = DocumentFile::withoutGlobalScope(OrgScope::class)->firstOrFail();

    expect($file->org_id)->toBe($owner->org_id)
        ->and($file->mime)->toBe('image/jpeg');
    Storage::disk('local')->assertExists($file->path);

    $this->get('/')->assertOk()->assertSee('bon.jpg');
    $this->get('/files/'.$file->id)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('refuses files that are neither image nor PDF, whatever their name says', function (): void {
    $owner = Membership::factory()->owner()->create();

    $this->actingAs($owner->user)
        ->post('/documents', ['file' => UploadedFile::fake()->createWithContent('bon.jpg', '<?php echo 1;')])
        ->assertSessionHasErrors('file');

    expect(Document::withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});

it('keeps files of other organisations and other members out of reach', function (): void {
    $owner = Membership::factory()->owner()->create();
    $this->actingAs($owner->user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')]);
    $file = DocumentFile::withoutGlobalScope(OrgScope::class)->firstOrFail();

    $stranger = Membership::factory()->owner()->create();
    $this->actingAs($stranger->user)->get('/files/'.$file->id)->assertNotFound();

    $member = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $owner->org_id]);
    $this->actingAs($member->user)->get('/files/'.$file->id)->assertNotFound();
});

it('does not let tax advisors upload', function (): void {
    $advisor = Membership::factory()->accountant()->create();

    $this->actingAs($advisor->user)
        ->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')])
        ->assertForbidden();
});
