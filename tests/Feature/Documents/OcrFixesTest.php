<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLearned;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Domain\Properties\PropertyValues;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Point 122.7 / A243: OCR corrections by position – route, searchable text, undo, re-scan, learned per sender.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->words = ['v' => 1, 'source' => 'tesseract', 'pages' => [['n' => 1, 'words' => [
        ['t' => 'Summe', 'x' => 0.1, 'y' => 0.5, 'w' => 0.1, 'h' => 0.02, 'c' => 91, 'l' => 0, 'b' => 0],
        ['t' => '12,0O', 'x' => 0.3, 'y' => 0.5, 'w' => 0.1, 'h' => 0.02, 'c' => 40, 'l' => 0, 'b' => 0],
        ['t' => '12,0O', 'x' => 0.3, 'y' => 0.6, 'w' => 0.1, 'h' => 0.02, 'c' => 40, 'l' => 1, 'b' => 0],
    ]]]];
    $this->make = function (?string $sender = null): Document {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'a.jpg']);
        $document->forceFill(['ocr_status' => 'done', 'ocr_pass' => 3, 'ocr_text' => "Summe 12,0O\n12,0O", 'ocr_words' => json_encode($this->words)])->save();
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'path' => 'x/a.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
        if ($sender !== null) {
            $this->actingAs($this->admin->user)->postJson('/documents/'.$document->id.'/properties', ['property' => 'sender', 'value' => $sender])->assertOk();
        }

        return $document;
    };
    // The second "12,0O" (line 2).
    $this->place = ['page' => 1, 'box' => ['x' => 0.3, 'y' => 0.6, 'w' => 0.1, 'h' => 0.02]];
});

it('stores a correction for who may edit, patches the searchable text and undoes it', function (): void {
    $document = ($this->make)();
    $reader = Membership::factory()->withRights(RoleRight::ViewAll)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($reader->user)->postJson('/documents/'.$document->id.'/words/fix', [...$this->place, 'text' => '12,00'])->assertForbidden();
    $stranger = Membership::factory()->owner()->create();
    $this->actingAs($stranger->user)->postJson('/documents/'.$document->id.'/words/fix', [...$this->place, 'text' => '12,00'])->assertNotFound();

    $this->actingAs($this->admin->user)->postJson('/documents/'.$document->id.'/words/fix', [...$this->place, 'text' => '12,00'])->assertOk()
        ->assertJsonPath('pages.0.words.2.t', '12,00')
        ->assertJsonPath('pages.0.words.1.t', '12,0O')
        ->assertJsonPath('fixes.0.o', '12,0O');
    expect($document->refresh()->ocr_text)->toBe("Summe 12,0O\n12,00");

    $this->postJson('/documents/'.$document->id.'/words/fix', ['page' => 1, 'box' => ['x' => 0.9, 'y' => 0.9, 'w' => 0.05, 'h' => 0.05], 'text' => 'x'])->assertUnprocessable();

    $this->postJson('/documents/'.$document->id.'/words/unfix', $this->place)->assertOk()->assertJsonPath('pages.0.words.2.t', '12,0O')->assertJsonPath('fixes', []);
    expect($document->refresh()->ocr_text)->toBe("Summe 12,0O\n12,0O")->and($document->ocr_fixes)->toBeNull();
});

it('keeps corrections through a new recognition and puts them into its text', function (): void {
    $document = ($this->make)();
    $this->actingAs($this->admin->user)->postJson('/documents/'.$document->id.'/words/fix', [...$this->place, 'text' => '12,00'])->assertOk();
    $document->refresh()->forceFill(['ocr_rescan' => true])->save();

    // The browser reads it again, the word sits a little elsewhere.
    $again = $this->words;
    $again['pages'][0]['words'][2]['x'] = 0.302;
    $this->post('/ocr/'.$document->id, ['text' => "Summe 12,0O\n12,0O", 'pass' => 3, 'words' => json_encode($again)])->assertNoContent();

    expect($document->refresh()->ocr_text)->toBe("Summe 12,0O\n12,00")
        ->and(OcrWords::forDocument($document)['pages'][0]['words'][2]['t'] ?? null)->toBe('12,00');
});

it('learns corrections per sender and offers them on that sender\'s other documents', function (): void {
    $first = ($this->make)('Muster GmbH');
    $this->actingAs($this->admin->user)->postJson('/documents/'.$first->id.'/words/fix', [...$this->place, 'text' => '12,00'])->assertOk();
    expect(OcrLearned::query()->withoutGlobalScope(OrgScope::class)->where('sender_key', 'muster gmbh')->value('count'))->toBe(1);

    $second = ($this->make)('muster  GmbH');
    $this->getJson('/documents/'.$second->id.'/words')->assertOk()->assertJsonPath('learned', ['12,0O' => '12,00'])
        ->assertJsonPath('pages.0.words.1.t', '12,0O'); // offered, not applied
    $other = ($this->make)('Beispiel AG');
    $this->getJson('/documents/'.$other->id.'/words')->assertOk()->assertJsonPath('learned', []);

    // Undo forgets it again.
    $this->postJson('/documents/'.$first->id.'/words/unfix', $this->place)->assertOk();
    expect(OcrLearned::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0);
    expect(app(PropertyValues::class)->get($second, 'sender'))->not->toBeNull();
});
