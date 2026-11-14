<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Tag;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Point 164.4: the main pages run a small, fixed number of queries, however many documents, tags and bookings
| there are (regression guard against N+1 and against counts run twice per page). Budgets = measured + 2.
*/
uses(RefreshDatabase::class)->group('db');

const QUERY_BUDGETS = ['home' => 8, 'documents' => 12, 'folder' => 14, 'window' => 14, 'bank' => 6, 'account' => 16, 'settings' => 4, 'badges' => 3, 'inbox' => 12];

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    $org = $this->admin->org_id;
    $make = fn (string $class, array $values) => $class::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $org, ...$values]);
    $this->seedData = function (int $n) use ($make, $org): void {
        $folder = $make(Folder::class, ['name' => 'Ordner '.$n]);
        $make(Folder::class, ['name' => 'Unter '.$n, 'parent_id' => $folder->id]);
        $tags = collect(range(1, 3))->map(fn (int $i) => $make(Tag::class, ['name' => "Tag $n-$i", 'color' => 'blue']));
        $account = $make(BankAccount::class, ['name' => 'Konto '.$n, 'currency' => 'EUR']);
        foreach (range(1, $n) as $i) {
            $doc = $make(Document::class, ['title' => "Beleg $n-$i.jpg", 'uploaded_by' => $this->admin->user_id, 'folder_id' => $i % 2 ? $folder->id : Folder::defaultIdFor($org)]);
            $make(DocumentFile::class, ['document_id' => $doc->id, 'kind' => 'original', 'page_no' => 1, 'path' => "x/$n-$i.jpg", 'mime' => 'image/jpeg', 'size' => 10, 'sha256' => str_repeat('a', 64)]);
            $doc->tags()->attach($tags->random()->id);
            $tx = $make(Transaction::class, ['bank_account_id' => $account->id, 'booking_date' => '2026-02-0'.(1 + $i % 9), 'amount_minor' => -100 * $i, 'currency' => 'EUR',
                'counterparty' => 'Firma '.$i, 'purpose' => 'Rechnung '.$i, 'fingerprint' => "f$n-$i"]);
            if ($i % 2) {
                $make(TransactionMatch::class, ['transaction_id' => $tx->id, 'document_id' => $doc->id]);
            }
        }
    };
    $this->counts = function (int $n): array {
        $folder = Folder::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Ordner '.$n)->value('id');
        $pages = [
            'home' => ['/', false], 'documents' => ['/documents', false], 'folder' => ['/documents?folder='.$folder, false],
            'window' => ['/documents?folder='.$folder, true], 'bank' => ['/bank', false],
            'account' => ['/bank/accounts/'.BankAccount::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Konto '.$n)->value('id'), false],
            'settings' => ['/settings', false], 'badges' => ['/badges', false], 'inbox' => ['/inbox', false],
        ];
        $counts = [];
        foreach ($pages as $key => [$url, $window]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url, $window ? ['X-DocHamster-Window' => '1'] : [])->assertOk();
            $counts[$key] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    };
});

it('keeps the query count of the main pages small and flat when the data grows', function (): void {
    ($this->seedData)(3);
    ($this->counts)(3); // first visit: default folder, palette and such are made once
    $small = ($this->counts)(3);
    ($this->seedData)(12);
    $big = ($this->counts)(12);

    foreach (QUERY_BUDGETS as $page => $budget) {
        // 4× the data, (almost) the same queries: an N+1 would add one per row.
        expect($big[$page])->toBeLessThanOrEqual($small[$page] + 1, "$page grows with the data");
        expect($big[$page])->toBeLessThanOrEqual($budget, "$page runs {$big[$page]} queries");
    }
});

it('sends the Server-Timing header and answers a window again with 304 while it is unchanged', function (): void {
    $this->get('/settings')->assertOk()->assertHeader('Server-Timing');
    $first = $this->get('/settings', ['X-DocHamster-Window' => '1'])->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->get('/settings', ['X-DocHamster-Window' => '1', 'If-None-Match' => $etag])->assertStatus(304);
    $this->get('/ping')->assertNoContent()->assertHeader('Server-Timing');
});
