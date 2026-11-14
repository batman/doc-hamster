<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Backup\BackupBuilder;
use App\Domain\Backup\BackupOptions;
use App\Domain\Backup\Restore\BackupRestorer;
use App\Domain\Backup\Restore\RestoreFailed;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Tag;
use App\Domain\Notes\Note;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Point 206.9: a small synthetic organisation goes out as a full backup and comes back into an empty instance with
| its original IDs – files, tags, bookings, matches, notes; the owner gets a new login, a member without an account
| is skipped. Nothing secret is in the ZIP.
*/
uses(RefreshDatabase::class)->group('db');

// A minimal valid PDF, synthetic.
const BACKUP_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = Membership::factory()->owner()->create();
    $this->member = Membership::factory()->create(['org_id' => $this->owner->org_id]);
    DB::table('users')->where('id', $this->owner->user_id)->update(['password_hash' => Hash::make('alter-schluessel-123')]);
    app(CurrentOrg::class)->set($this->owner);
    $orgId = $this->owner->org_id;

    $this->document = Document::query()->create(['org_id' => $orgId, 'uploaded_by' => $this->member->user_id, 'title' => 'Rechnung Muster GmbH', 'doc_date' => '2026-03-02']);
    $path = $orgId.'/2026/03/'.Str::lower((string) Str::ulid()).'.pdf';
    Storage::disk('local')->put($path, BACKUP_PDF);
    $this->file = DocumentFile::query()->create(['org_id' => $orgId, 'document_id' => $this->document->id, 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen(BACKUP_PDF), 'sha256' => hash('sha256', BACKUP_PDF)]);
    $this->tag = Tag::query()->firstOrFail();
    $this->document->tags()->sync([$this->tag->id]);

    $account = BankAccount::query()->create(['org_id' => $orgId, 'name' => 'Hausbank', 'currency' => 'EUR', 'iban' => 'DE00000000000000000000', 'kind' => 'business']);
    $this->transaction = Transaction::query()->create(['org_id' => $orgId, 'bank_account_id' => $account->id, 'booking_date' => '2026-03-03', 'amount_minor' => -1234, 'currency' => 'EUR', 'counterparty' => 'Muster GmbH', 'purpose' => 'Rechnung 1', 'fingerprint' => str_repeat('a', 64)]);
    DB::table('matches')->insert(['id' => Str::lower((string) Str::ulid()), 'org_id' => $orgId, 'transaction_id' => $this->transaction->id, 'document_id' => $this->document->id, 'state' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    $this->note = Note::query()->create(['org_id' => $orgId, 'user_id' => $this->member->user_id, 'body' => 'Belege März sammeln']);
});

/** Builds the backup, then empties the instance like a fresh server. */
function backupAndWipe(?string $password = null): string
{
    $owner = test()->owner;
    [$path] = BackupBuilder::toFile(Str::lower((string) Str::ulid()), $owner->org_id, new BackupOptions, $owner->user_id, $password);
    $zip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dh-restore-'.bin2hex(random_bytes(4)).'.zip';
    copy(Storage::disk('local')->path($path), $zip);

    app()->forgetInstance(CurrentOrg::class);
    DB::table('organizations')->delete();
    DB::table('users')->delete();
    Storage::fake('local');

    return $zip;
}

it('restores a full backup into an empty instance with the original IDs', function (): void {
    $orgId = $this->owner->org_id;
    $zip = backupAndWipe();

    // Nothing secret travels: no password hashes, no remember tokens.
    $read = new ZipArchive;
    $read->open($zip);
    $users = (string) $read->getFromName('data/users.json');
    $read->close();
    expect($users)->not->toContain('password_hash')->not->toContain('remember_token')->not->toContain('$argon')->not->toContain('$2y$');

    $report = app(BackupRestorer::class)->restore($zip, null, 'inhaberin', 'neues-passwort-1234');
    unlink($zip);

    $owner = User::query()->findOrFail($this->owner->user_id);
    $file = DB::table('document_files')->where('id', $this->file->id)->first();
    expect($report->skippedPeople)->toHaveCount(1)
        ->and(DB::table('organizations')->where('id', $orgId)->exists())->toBeTrue()
        ->and($owner->username)->toBe('inhaberin')
        ->and(Hash::check('neues-passwort-1234', (string) $owner->password_hash))->toBeTrue()
        ->and(DB::table('memberships')->where('org_id', $orgId)->pluck('role')->all())->toBe(['owner'])
        ->and(DB::table('documents')->where('id', $this->document->id)->value('uploaded_by'))->toBeNull()
        ->and(DB::table('document_tags')->where('document_id', $this->document->id)->pluck('tag_id')->all())->toBe([$this->tag->id])
        ->and(DB::table('transactions')->where('id', $this->transaction->id)->value('amount_minor'))->toEqual(-1234)
        ->and(DB::table('matches')->where('transaction_id', $this->transaction->id)->value('document_id'))->toBe($this->document->id)
        ->and(DB::table('notes')->where('id', $this->note->id)->value('user_id'))->toBe($owner->id)
        ->and($file?->mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->get((string) $file?->path))->toBe(BACKUP_PDF);
});

it('restores a password-protected backup only with the right password', function (): void {
    $zip = backupAndWipe('ein-langes-zip-passwort');

    $read = new ZipArchive;
    $read->open($zip);
    expect($read->numFiles)->toBe(2)->and($read->locateName('manifest.json'))->toBeFalse();
    $read->close();

    $restore = fn (?string $password) => app(BackupRestorer::class)->restore($zip, $password, 'inhaberin', 'neues-passwort-1234');
    foreach ([null => 'password_needed', 'falsches-passwort-1' => 'password_wrong'] as $password => $reason) {
        try {
            $restore($password === '' ? null : (string) $password);
            $this->fail('Expected a refusal.');
        } catch (RestoreFailed $e) {
            expect($e->reason)->toBe($reason);
        }
    }

    $restore('ein-langes-zip-passwort');
    unlink($zip);
    expect(DB::table('documents')->where('id', $this->document->id)->exists())->toBeTrue();
});

it('refuses to restore into an instance that holds data', function (): void {
    [$path] = BackupBuilder::toFile(Str::lower((string) Str::ulid()), $this->owner->org_id, new BackupOptions, $this->owner->user_id);

    expect(fn () => app(BackupRestorer::class)->restore(Storage::disk('local')->path($path), null, 'inhaberin', 'neues-passwort-1234'))
        ->toThrow(RestoreFailed::class, __('backup.restore.errors.not_empty'));
});

it('keeps a period backup to the documents and bookings of that period', function (): void {
    $options = BackupOptions::from(['range' => 'period', 'from' => '2026-04-01', 'to' => '2026-04-30', 'parts' => BackupOptions::SECTIONS]);
    [$path] = BackupBuilder::toFile(Str::lower((string) Str::ulid()), $this->owner->org_id, $options, $this->owner->user_id);

    $read = new ZipArchive;
    $read->open(Storage::disk('local')->path($path));
    $manifest = json_decode((string) $read->getFromName('manifest.json'), true);
    $documents = json_decode((string) $read->getFromName('data/documents.json'), true);
    $transactions = json_decode((string) $read->getFromName('data/transactions.json'), true);
    $read->close();

    expect($manifest['full'])->toBeFalse()->and($documents)->toBe([])->and($transactions)->toBe([]);
});
