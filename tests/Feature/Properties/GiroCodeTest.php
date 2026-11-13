<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Payments\GiroCode;
use App\Domain\Payments\SenderHint;
use App\Domain\Properties\PropertyValues;
use App\Events\TextRecognized;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Points 120, 122.5, A236-A239, A249, A253: suggestions after recognition, learning per sender, the GiroCode card and its SVG.
*/
uses(RefreshDatabase::class)->group('db');

const GIRO_TEXT = "Muster Handwerk GmbH · Werkstraße 5 · 12345 Musterstadt\nMax Mustermann\nRechnungsnummer: RE-2026-0042\n"
    ."Rechnungsdatum: 03.11.2026\nNettobetrag 340,00 €\nzzgl. 19 % MwSt 64,60 €\nGesamtbetrag 404,60 €\nZahlbar innerhalb 14 Tagen.\n"
    ."Kontoinhaber: Muster Handwerk GmbH\nIBAN: DE89 3704 0044 0532 0130 00\nUnser Konto: DE02 1203 0000 0000 2020 51";

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    $this->values = app(PropertyValues::class);
    $this->doc = function (string $text = GIRO_TEXT): Document {
        $doc = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'rechnung.pdf']);
        $doc->forceFill(['ocr_text' => $text, 'ocr_status' => 'done'])->save();

        return $doc;
    };
});

it('writes suggestions after recognition without touching confirmed values or the own IBAN (A253)', function (): void {
    BankAccount::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'name' => 'Giro', 'iban' => 'DE89370400440532013000']);
    $doc = ($this->doc)();
    $this->values->set($doc, 'invoice_number', 'RE-SELBST');

    TextRecognized::dispatch($doc->id);

    expect($this->values->get($doc, 'invoice_number')?->value_text)->toBe('RE-SELBST')
        ->and($this->values->get($doc, 'invoice_number')?->state)->toBe('confirmed')
        ->and($this->values->get($doc, 'invoice_amount')?->value_int)->toBe(40460)
        ->and($this->values->get($doc, 'invoice_amount')?->state)->toBe('suggested')
        ->and($this->values->get($doc, 'iban')?->value_text)->toBe('DE02120300000000202051')
        ->and($this->values->get($doc, 'sender')?->value_text)->toBe('Muster Handwerk GmbH')
        ->and($this->values->get($doc, 'due_date')?->value_date?->format('Y-m-d'))->toBe('2026-11-17');
});

it('learns the amount place per sender when a person confirms it (A236)', function (): void {
    $doc = ($this->doc)("Muster Strom AG\nAbschlag 50,00 €\nRechnungsbetrag 120,00 €");
    $this->values->set($doc, 'sender', 'Muster Strom AG');
    $this->values->set($doc, 'invoice_amount', '50,00 €');

    $hint = SenderHint::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($hint->sender_key)->toBe('muster strom')->and($hint->amount_keyword)->toBe('abschlag');

    $next = ($this->doc)("Muster Strom AG\nAbschlag 55,00 €\nRechnungsbetrag 130,00 €");
    TextRecognized::dispatch($next->id);
    expect($this->values->get($next, 'invoice_amount')?->value_int)->toBe(5500);
});

it('builds the EPC payload', function (): void {
    expect(GiroCode::payload('Muster Handwerk GmbH', 'DE89 3704 0044 0532 0130 00', 'cobadeffxxx', 40460, 'Rechnung RE-1, Kunde 4711'))
        ->toBe("BCD\n002\n1\nSCT\nCOBADEFFXXX\nMuster Handwerk GmbH\nDE89370400440532013000\nEUR404.60\n\n\nRechnung RE-1, Kunde 4711")
        ->and(GiroCode::amount(5))->toBe('EUR0.05')
        ->and(GiroCode::discounted(40460, 30000))->toBe(39246)
        ->and(GiroCode::reference('RE-1', '4711'))->toBe('Rechnung RE-1, Kunde 4711')
        ->and(GiroCode::svg(GiroCode::payload('Max Mustermann', 'DE89370400440532013000', null, 100)))->toContain('<svg');
});

it('serves the code only for a complete euro transfer and only to who may see the document (A239, A250)', function (): void {
    $doc = ($this->doc)();
    $this->get("/documents/{$doc->id}/girocode.svg")->assertStatus(422);

    $this->values->set($doc, 'sender', 'Muster Handwerk GmbH');
    $this->values->set($doc, 'iban', 'DE89370400440532013000');
    $this->values->set($doc, 'invoice_amount', '404,60 €');
    $this->get("/documents/{$doc->id}/girocode.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get("/documents/{$doc->id}/girocode.svg?amount=12,00%20USD")->assertStatus(422);
    $this->get("/documents/{$doc->id}/girocode")->assertOk()->assertSee('Mit der Banking-App scannen');

    $other = Membership::factory()->owner()->create();
    $this->actingAs($other->user);
    $this->get("/documents/{$doc->id}/girocode.svg")->assertNotFound();
});

it('marks a document as paid and back (A238, A249)', function (): void {
    $doc = ($this->doc)();
    $this->values->set($doc, 'iban', 'DE89370400440532013000');
    $today = now()->format('d.m.Y');

    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'paid_at', 'value' => $today])->assertOk();
    $this->get("/documents/{$doc->id}/girocode")->assertOk()->assertSee('Bezahlt am '.$today)->assertDontSee('Mit der Banking-App scannen');

    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'paid_at', 'value' => ''])->assertOk();
    expect($this->values->get($doc, 'paid_at'))->toBeNull();
    $this->get("/documents/{$doc->id}/girocode")->assertOk()->assertSee('Als bezahlt markieren');
});
