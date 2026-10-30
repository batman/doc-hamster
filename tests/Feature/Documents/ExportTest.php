<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| MVP: ZIP export per period.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->create(['password_hash' => 'ein-langes-test-passwort']);
    Membership::factory()->create(['user_id' => $this->user->id, 'role' => Role::Owner]);
    $this->actingAs($this->user);
    $this->post('/documents', ['file' => UploadedFile::fake()->image('=bon ä.jpg')]);
});

it('exports the period as ZIP with the receipts and an index', function (): void {
    $response = $this->post('/export', ['from' => today()->format('Y-m-d'), 'to' => today()->format('Y-m-d'), 'password' => 'ein-langes-test-passwort']);

    $response->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $index = (string) $zip->getFromName('index.csv');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    unlink($path);

    expect($names)->toHaveCount(2)
        ->and(implode(' ', $names))->toContain('belege/'.now()->format('Y-m').'/')
        ->and($index)->toStartWith("\xEF\xBB\xBF")
        ->and($index)->toContain("'=bon ä.jpg")
        ->and($index)->not->toContain('Datei fehlt');
});

it('needs the password and a period with receipts', function (): void {
    $today = today()->format('Y-m-d');

    $this->post('/export', ['from' => $today, 'to' => $today, 'password' => 'falsch'])->assertSessionHasErrors('password');
    $this->post('/export', ['from' => '2001-01-01', 'to' => '2001-01-31', 'password' => 'ein-langes-test-passwort'])->assertSessionHasErrors('from');
});

it('is for owners only', function (): void {
    $member = Membership::factory()->create();

    $this->actingAs($member->user)->get('/export')->assertForbidden();
});
