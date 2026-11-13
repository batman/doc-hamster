<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Domain\Properties\PropertyValues;
use App\Infrastructure\Environment\OcrProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Points 123-125: Texterkennung as a module of its own, the Live card's status JSON and foldable cards.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->make = function (string $title, ?string $status = 'done'): Document {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title]);
        $document->forceFill(['ocr_status' => $status])->save();

        return $document;
    };
});

it('opens Texterkennung as a module of its own without the settings columns', function (): void {
    ($this->make)('a.jpg', 'pending');

    $this->actingAs($this->admin->user)->get('/ocr')->assertOk()
        ->assertSee('data-window-kind="ocr"', false)->assertDontSee('data-cols', false)
        ->assertSee('Live')->assertSee('Wartet auf Dokumente');
    $this->get('/settings/ocr')->assertOk()->assertSee('data-cols', false)->assertSee('Live');
    // The OCR widget links to it.
    $this->get('/')->assertSee('href="'.route('ocr.live').'"', false);

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->get('/ocr')->assertForbidden();
});

it('gives the Live card the current page, figures and the last finished documents', function (): void {
    $org = $this->admin->org_id;
    $done = [];
    foreach (range(1, 12) as $i) {
        $done[$i] = ($this->make)('doc'.$i.'.pdf');
        OcrLog::record($org, 'app', $i === 12 ? 'failed' : 'recognized', ['document_id' => $done[$i]->id, 'duration_ms' => 4000, 'pages' => 2]);
    }
    app(PropertyValues::class)->set($done[11], 'invoice_amount', '12,34 €');
    app(PropertyValues::class)->set($done[10], 'sender', 'Muster GmbH');
    $current = ($this->make)('jetzt.pdf', 'pending');
    OcrProgress::start($current);
    OcrProgress::page($current->id, 2, 3);

    $json = $this->actingAs($this->admin->user)->getJson('/ocr/status?live=1')->assertOk()
        ->assertJsonPath('current.title', 'jetzt.pdf')->assertJsonPath('current.page', 2)->assertJsonPath('current.pages', 3)
        ->assertJsonPath('current.engine', 'server')
        ->assertJsonPath('figures.per_hour', 12)->assertJsonPath('figures.seconds_per_page', 2)
        ->json();
    expect($json['figures']['remaining_seconds'])->toBe(4)
        ->and($json['recent'])->toHaveCount(10)
        ->and($json['recent'][0])->toMatchArray(['title' => 'doc12.pdf', 'ok' => false])
        ->and($json['recent'][1])->toMatchArray(['title' => 'doc11.pdf', 'ok' => true, 'value' => '12,34 €'])
        ->and($json['recent'][2]['value'])->toBe('Muster GmbH')
        ->and($json['recent'][0]['url'])->toBe(route('documents.show', $done[12]->id));

    // Without live=1 the poll stays small.
    $this->getJson('/ocr/status')->assertOk()->assertJsonMissingPath('recent');
});

it('draws foldable cards folded or open from the page default and the remembered choice', function (): void {
    $this->actingAs($this->admin->user)->get('/settings/ocr')->assertOk()
        ->assertSee('class="card card-fold is-folded" data-card-fold="ocr.cron"', false)
        ->assertSee('kein Cronjob');

    $this->withUnencryptedCookie('dh_folds', 'ocr.cron:0|ocr.progress:1')->get('/settings/ocr')->assertOk()
        ->assertSee('class="card card-fold" data-card-fold="ocr.cron"', false)
        ->assertSee('class="card card-fold is-folded" data-card-fold="ocr.progress"', false);
});
