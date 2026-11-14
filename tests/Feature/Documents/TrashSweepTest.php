<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\TrashSettings;
use App\Domain\Documents\TrashSweeper;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Point 207: "Papierkorb leeren" with a safety net and the daily Papierkorb job – which documents it deletes for good
| (undo period ended, longer in the Papierkorb than the setting, never with "nie automatisch leeren"), and the undo.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
});

function sweepDoc(object $test, string $name, ?string $deletedAt = null, ?string $purgeAfter = null): Document
{
    $test->post('/documents', ['file' => UploadedFile::fake()->image($name)]);
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', $name)->firstOrFail();
    if ($deletedAt !== null) {
        DB::table('documents')->where('id', $document->id)->update(['deleted_at' => $deletedAt, 'purge_after' => $purgeAfter]);
    }

    return $document;
}

function sweepLeft(): array
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->withTrashed()->orderBy('title')->pluck('title')->all();
}

it('deletes for good what was emptied once the undo period ended, and what lay in the Papierkorb longer than the setting', function (): void {
    Carbon::setTestNow('2026-12-31 03:00:00');
    sweepDoc($this, 'aktiv.jpg');
    $old = sweepDoc($this, 'alt.jpg', '2026-11-30 02:00:00');           // 31 days in the Papierkorb (default 30)
    sweepDoc($this, 'frisch.jpg', '2026-12-10 10:00:00');              // 21 days
    sweepDoc($this, 'abgelaufen.jpg', '2026-12-30 10:00:00', '2026-12-31 02:59:59');
    sweepDoc($this, 'noch-frist.jpg', '2026-01-01 10:00:00', '2027-01-02 00:00:00'); // old, but emptied and still undoable
    $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $old->id)->firstOrFail();

    $counts = app(TrashSweeper::class)->run();

    expect($counts)->toMatchArray(['undo_ended' => 1, 'kept_days' => 1])
        ->and(sweepLeft())->toBe(['aktiv.jpg', 'frisch.jpg', 'noch-frist.jpg'])
        ->and(DB::table('audit_log')->where('action', 'document.purged')->count())->toBe(2)
        ->and(DB::table('log_entries')->where('event', 'document.trash_swept')->count())->toBe(1);
    Storage::disk('local')->assertMissing($file->path);
});

it('follows the retention setting and never empties automatically with "nie"', function (): void {
    Carbon::setTestNow('2026-12-31 03:00:00');
    sweepDoc($this, 'acht-tage.jpg', '2026-12-23 02:00:00');
    sweepDoc($this, 'jahr.jpg', '2025-12-01 00:00:00');
    $organization = $this->admin->organization;

    TrashSettings::save($organization, ['keep' => 0]);
    app(TrashSweeper::class)->sweep($organization->refresh());
    expect(sweepLeft())->toBe(['acht-tage.jpg', 'jahr.jpg']);

    TrashSettings::save($organization, ['keep' => 7]);
    app(TrashSweeper::class)->sweep($organization->refresh());
    expect(sweepLeft())->toBe([]);
});

it('empties into the undo state with the chosen period and brings an emptying back into the Papierkorb', function (): void {
    Carbon::setTestNow('2026-12-01 12:00:00');
    TrashSettings::save($this->admin->organization, ['undo' => 7]);
    foreach (['x.jpg', 'y.jpg'] as $name) {
        $this->postJson('/documents/bulk/trash', ['ids' => [sweepDoc($this, $name)->id]]);
    }

    $answer = $this->postJson('/documents/trash/empty')->assertOk()
        ->assertJson(['message' => 'Die Dokumente werden in 7 Tagen endgültig gelöscht.', 'batch' => '2026-12-08 12:00:00'])->json();
    $this->get('/documents/trash')->assertOk()->assertSee('Leerung rückgängig machen (2 Dokumente, bis 08.12.)')->assertSee('Der Papierkorb ist leer.');
    // Emptied ones can't be restored or dragged out one by one, only by the undo.
    $this->postJson('/documents/bulk/restore', ['ids' => [sweepDoc($this, 'z.jpg')->id, Document::query()->withoutGlobalScope(OrgScope::class)->withTrashed()->where('title', 'x.jpg')->value('id')]])
        ->assertJson(['message' => 'Nichts wiederhergestellt']);

    $this->postJson($answer['undo'], ['batch' => $answer['batch']])->assertOk()->assertJson(['message' => '2 Dokumente wiederhergestellt']);

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->inTrash()->count())->toBe(2)
        ->and(Document::query()->withoutGlobalScope(OrgScope::class)->emptied()->count())->toBe(0);
});

it('no longer undoes an emptying whose period has ended', function (): void {
    Carbon::setTestNow('2026-12-31 03:00:00');
    sweepDoc($this, 'vorbei.jpg', '2026-12-01 10:00:00', '2026-12-15 10:00:00');

    $this->postJson('/documents/trash/unempty', ['batch' => '2026-12-15 10:00:00'])->assertOk()->assertJson(['message' => 'Nichts wiederhergestellt']);

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->inTrash()->count())->toBe(0);
});
