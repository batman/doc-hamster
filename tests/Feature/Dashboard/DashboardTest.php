<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Dashboard\DashboardLayout;
use App\Domain\Dashboard\DashboardWidgets;
use App\Domain\Dashboard\UserDashboard;
use App\Domain\Documents\Document;
use App\Domain\Log\LogEntry;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Domain\Properties\PropertyValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Points 175, 176, A349-A353: Home is a dashboard per person; the notes board moved to /notes.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    app(CurrentOrg::class)->set($this->admin);
});

it('keeps only known widgets, sizes and safe pins (A350, A351)', function (): void {
    $layout = DashboardLayout::normalize([
        'widgets' => [['type' => 'inbox', 'size' => 'xl'], ['type' => 'evil'], ['type' => 'inbox', 'size' => 's'], 'notes', ['type' => 'photo', 'size' => 's']],
        'pins' => [
            ['url' => '/documents?folder=1', 'title' => ' Belege ', 'icon' => 'folder'],
            ['url' => 'javascript:alert(1)', 'title' => 'x'],
            ['url' => 'http://example.com', 'title' => 'x'],
            ['url' => '//example.com/x', 'title' => 'x'],
            ['url' => 'https://example.com/a', 'title' => '', 'icon' => 'no-such-icon'],
        ],
    ]);

    expect($layout['widgets'])->toBe([['type' => 'inbox', 'size' => 'm'], ['type' => 'notes', 'size' => 'm'], ['type' => 'photo', 'size' => 's']])
        ->and($layout['pins'])->toBe([
            ['url' => '/documents?folder=1', 'title' => 'Belege', 'icon' => 'folder'],
            ['url' => 'https://example.com/a', 'title' => 'example.com', 'icon' => 'external-link'],
        ])
        ->and(DashboardLayout::pin('https://app.example.com/bank?status=open', 'Bank', 'building-bank', 'https://app.example.com'))
        ->toBe(['url' => '/bank?status=open', 'title' => 'Bank', 'icon' => 'building-bank'])
        ->and(DashboardLayout::normalize(null))->toBe(DashboardLayout::default());
});

it('shows Home as a dashboard with the default widgets and the board under /notes (175, A352)', function (): void {
    $this->actingAs($this->admin->user)->get('/')->assertOk()
        ->assertSee('data-window-kind="dashboard"', false)
        ->assertSee('data-widget="greeting"', false)->assertSee('data-widget="due"', false)
        ->assertSee(__('dashboard.photo_hint'))->assertSee(__('dashboard.quick_hint'))
        ->assertSee('href="'.route('notes.index').'"', false);
    $this->get('/notes')->assertOk()->assertSee('data-notes', false);
});

it('saves the layout per person and keeps other people apart (A350, A353)', function (): void {
    $other = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);

    $this->actingAs($this->admin->user)->postJson('/dashboard/layout', ['widgets' => [['type' => 'notes', 'size' => 'l'], ['type' => 'ocr', 'size' => 's']]])->assertOk();
    $this->postJson('/dashboard/widgets', ['type' => 'drop'])->assertOk()->assertJsonPath('message', __('dashboard.added'));
    expect(UserDashboard::of($this->admin->user_id)->cleanLayout()['widgets'])
        ->toBe([['type' => 'notes', 'size' => 'l'], ['type' => 'ocr', 'size' => 's'], ['type' => 'drop', 'size' => 's']]);

    // A right taken away hides a widget, it is not lost; a widget without the right cannot be added.
    $this->actingAs($other->user)->postJson('/dashboard/widgets', ['type' => 'bookings'])->assertStatus(422);
    $this->get('/')->assertOk()->assertSee('data-widget="greeting"', false)->assertDontSee('data-widget="ocr"', false);
});

it('pins a window and an https link, refuses other addresses, and unpins (A351)', function (): void {
    $this->actingAs($this->admin->user);
    $this->postJson('/dashboard/pins', ['url' => url('/documents?folder=abc'), 'title' => 'Belege', 'icon' => 'folder'])->assertOk();
    $this->postJson('/dashboard/pins', ['url' => '/documents?folder=abc', 'title' => 'Belege'])->assertOk()->assertJsonPath('message', __('dashboard.pin_exists', ['title' => 'Belege']));
    $this->postJson('/dashboard/pins', ['url' => 'javascript:alert(1)'])->assertStatus(422);
    $this->postJson('/dashboard/pins', ['url' => 'https://example.com/hilfe', 'title' => 'Hilfe'])->assertOk();

    $this->get('/')->assertSee('href="/documents?folder=abc"', false)->assertSee('href="https://example.com/hilfe"', false)->assertSee('target="_blank"', false);
    $this->postJson('/dashboard/pins/delete', ['url' => '/documents?folder=abc'])->assertOk();
    expect(array_column(UserDashboard::of($this->admin->user_id)->cleanLayout()['pins'], 'url'))->toBe(['https://example.com/hilfe']);
});

it('lists unpaid invoices by due date, only visible ones (A349)', function (): void {
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $values = app(PropertyValues::class);
    $doc = fn (string $title, string $by): Document => Document::query()->create(['uploaded_by' => $by, 'title' => $title]);

    $later = $doc('Strom', $this->admin->user_id);
    $values->set($later, 'due_date', '30.12.2026');
    $values->set($later, 'invoice_amount', '50,00 €');
    $sooner = $doc('Miete', $this->admin->user_id);
    $values->set($sooner, 'due_date', '01.11.2026');
    $paid = $doc('Wasser', $this->admin->user_id);
    $values->set($paid, 'due_date', '15.11.2026');
    $values->set($paid, 'paid_at', '14.11.2026');
    $own = $doc('Handy', $clerk->user_id);
    $values->set($own, 'due_date', '20.11.2026');

    $rows = (new DashboardWidgets($this->admin->user_id, true))->due();
    expect(array_map(static fn (array $r): string => $r['document']->title, $rows))->toBe(['Miete', 'Handy', 'Strom'])
        ->and($rows[2]['amount'])->toBe(Transaction::money(5000, 'EUR'));

    $clerkRows = (new DashboardWidgets($clerk->user_id, false))->due();
    expect(array_map(static fn (array $r): string => $r['document']->title, $clerkRows))->toBe(['Handy']);
});

it('sums open bookings per account and shows only the person’s own activity (A349)', function (): void {
    $account = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
    foreach ([-1000, -250] as $amount) {
        Transaction::query()->create(['bank_account_id' => $account->id, 'booking_date' => '2026-11-02', 'amount_minor' => $amount, 'currency' => 'EUR',
            'counterparty' => 'Muster GmbH', 'fingerprint' => Str::random(24)]);
    }
    $other = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    LogEntry::write('general', 'event', 'Dokument hochgeladen: mine.pdf', 'document.uploaded', $this->admin->org_id, $this->admin->user_id);
    LogEntry::write('general', 'event', 'Dokument hochgeladen: theirs.pdf', 'document.uploaded', $this->admin->org_id, $other->user_id);
    LogEntry::write('general', 'event', 'Angemeldet', 'auth.login', $this->admin->org_id, $this->admin->user_id);

    $widgets = new DashboardWidgets($this->admin->user_id, true);
    $bookings = $widgets->bookings();
    expect($bookings)->toHaveCount(1)->and($bookings[0]['count'])->toBe(2)->and($bookings[0]['sum'])->toBe(Transaction::signed(-1250))
        ->and(array_column($widgets->activity($this->admin->org_id), 'message'))->toBe(['Dokument hochgeladen: mine.pdf']);
});

it('keeps the photo private to its owner (A349)', function (): void {
    $other = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($this->admin->user)->post('/dashboard/photo', ['photo' => UploadedFile::fake()->image('ich.png', 40, 40)], ['Accept' => 'application/json'])->assertOk();
    $this->postJson('/dashboard/photo', ['photo' => UploadedFile::fake()->create('ich.png', 5, 'image/png')])->assertStatus(422);

    $this->get('/dashboard/photo')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($other->user)->get('/dashboard/photo')->assertNotFound();
    expect(UserDashboard::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(1);
});
