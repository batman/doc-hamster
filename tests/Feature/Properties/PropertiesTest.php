<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Points 122.1-122.6, A244-A252: document properties – defaults, values, card routes, search, columns, tag limit.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    $this->doc = fn (string $title): Document => Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title]);
    $this->prop = fn (string $key): Property => Property::query()->withoutGlobalScope(OrgScope::class)
        ->where('org_id', $this->admin->org_id)->where('key', $key)->firstOrFail();
});

it('gives every organisation the default properties, paid_at included', function (): void {
    $org = Organization::factory()->create();
    $keys = Property::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org->id)->orderBy('sort')->pluck('type', 'key')->all();

    expect(array_keys($keys))->toBe(array_keys(Property::DEFAULTS))
        ->and($keys['paid_at'])->toBe('date')->and($keys['invoice_amount'])->toBe('amount');
});

it('sets, confirms and removes values through the JSON routes', function (): void {
    $doc = ($this->doc)('rechnung.pdf');

    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'invoice_amount', 'value' => '1.234,56 €', 'page' => 1, 'box' => ['x' => .1, 'y' => .2, 'w' => .1, 'h' => .02]])
        ->assertOk()->assertJson(['value' => '1.234,56 €']);
    $value = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($value->value_int)->toBe(123456)->and($value->currency)->toBe('EUR')->and($value->state)->toBe('confirmed')->and($value->page)->toBe(1);

    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'invoice_date', 'value' => '31.02.2026'])->assertStatus(422)->assertJsonValidationErrors('value');
    // Click-to-edit sends the property as "field".
    $this->postJson("/documents/{$doc->id}/properties", ['field' => ($this->prop)('iban')->id, 'value' => 'DE89 3704 0044 0532 0130 00'])->assertOk()->assertJson(['value' => 'DE89 3704 0044 0532 0130 00']);

    app(PropertyValues::class)->set($doc, 'due_date', '01.04.26', 'suggested');
    $due = ($this->prop)('due_date');
    $this->get("/documents/{$doc->id}/properties")->assertOk()->assertSee('vorgeschlagen');
    $this->postJson("/documents/{$doc->id}/properties/{$due->id}/confirm")->assertOk();
    expect(app(PropertyValues::class)->get($doc, 'due_date')?->state)->toBe('confirmed');

    $this->postJson("/documents/{$doc->id}/properties/{$due->id}/delete")->assertOk();
    expect(app(PropertyValues::class)->get($doc, 'due_date'))->toBeNull();
});

it('never lets a suggestion overwrite a confirmed value', function (): void {
    $doc = ($this->doc)('rechnung.pdf');
    $values = app(PropertyValues::class);

    $values->set($doc, 'invoice_number', 'RE-1');
    $values->set($doc, 'invoice_number', 'RE-2', 'suggested');
    expect($values->get($doc, 'invoice_number')?->value_text)->toBe('RE-1');

    $values->set($doc, 'sender', 'Muster GmbH', 'suggested');
    $values->set($doc, 'sender', 'Beispiel AG', 'suggested');
    expect($values->get($doc, 'sender')?->value_text)->toBe('Beispiel AG');

    expect(fn () => $values->set($doc, 'iban', 'DE00 0000 0000 0000 0000 00'))->toThrow(ValidationException::class);
});

it('keeps a property to documents with its tags (A246)', function (): void {
    $doc = ($this->doc)('vertrag.pdf');
    $tag = Tag::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->admin->org_id)->firstOrFail();
    ($this->prop)('iban')->forceFill(['tag_ids' => [$tag->id]])->save();

    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'iban', 'value' => 'DE89370400440532013000'])->assertStatus(422);
    expect(collect($this->getJson("/documents/{$doc->id}/properties")->json('properties'))->pluck('key'))->not->toContain('iban');

    $doc->tags()->attach($tag->id);
    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'iban', 'value' => 'DE89370400440532013000'])->assertOk();
});

it('filters the search by properties and sorts the details view by a property column', function (): void {
    $values = app(PropertyValues::class);
    $cheap = ($this->doc)('billig.pdf');
    $dear = ($this->doc)('teuer.pdf');
    ($this->doc)('ohne.pdf');
    $values->set($cheap, 'invoice_amount', '10,00');
    $values->set($dear, 'invoice_amount', '50,00');
    $values->set($dear, 'sender', 'Muster GmbH');
    $amount = ($this->prop)('invoice_amount');
    $sender = ($this->prop)('sender');

    $this->get("/documents?p_{$amount->id}_from=20")->assertSee('teuer.pdf')->assertDontSee('billig.pdf')->assertDontSee('ohne.pdf');
    $this->get("/documents?p_{$amount->id}_has=no")->assertSee('ohne.pdf')->assertDontSee('teuer.pdf');
    $this->get("/documents?p_{$sender->id}_q=muster")->assertSee('teuer.pdf')->assertDontSee('billig.pdf')->assertSee('Absender: enthält');

    $this->postJson('/documents/columns', ['toggle' => $amount->id])->assertOk();
    expect($this->admin->user->fresh()->document_columns)->toBe([$amount->id]);
    $this->get("/documents?view=details&sort=prop:{$amount->id}&dir=desc")
        ->assertSeeInOrder(['teuer.pdf', 'billig.pdf', 'ohne.pdf'])->assertSee('50,00 €');
});

it('puts the active properties into the export index (A245)', function (): void {
    Storage::fake('local');
    $this->admin->user->forceFill(['password_hash' => 'ein-langes-test-passwort'])->save();
    $this->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')]);
    $doc = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'bon.jpg')->firstOrFail();
    app(PropertyValues::class)->set($doc, 'invoice_amount', '12,50');

    $path = $this->post('/export', ['from' => today()->format('Y-m-d'), 'to' => today()->format('Y-m-d'), 'password' => 'ein-langes-test-passwort'])
        ->assertOk()->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $index = (string) $zip->getFromName('index.csv');
    $zip->close();
    unlink($path);

    expect($index)->toContain('Rechnungsbetrag')->toContain('Bezahlt am')->toContain('12,50 €');
});

it('manages properties in the settings; default ones only switch off', function (): void {
    $this->get('/settings/properties')->assertOk()->assertSee('Rechnungsbetrag');
    $this->post('/settings/properties', ['name' => 'Kostenstelle', 'type' => 'choice', 'options' => ''])->assertSessionHasErrors('options');
    $this->post('/settings/properties', ['name' => 'Kostenstelle', 'type' => 'choice', 'options' => "Büro\nAuto"])->assertRedirect();
    $own = Property::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Kostenstelle')->firstOrFail();
    expect($own->options)->toBe(['Büro', 'Auto']);

    $default = ($this->prop)('paid_at');
    $this->postJson("/settings/properties/{$default->id}", ['active' => '0'])->assertOk()->assertJsonPath('property.active', false);
    $this->post("/settings/properties/{$default->id}/delete")->assertSessionHasErrors('property');
    $this->post("/settings/properties/{$own->id}/delete")->assertRedirect();
    expect(Property::query()->withoutGlobalScope(OrgScope::class)->whereKey($own->id)->exists())->toBeFalse()
        ->and(Property::query()->withoutGlobalScope(OrgScope::class)->whereKey($default->id)->exists())->toBeTrue();
});
