<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use App\Domain\Org\SpeedOptions;
use App\Http\Controllers\SpeedController;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Point 164.7: Einstellungen → Geschwindigkeit – options reach the page, self tests answer.
*/
uses(RefreshDatabase::class)->group('db');

it('saves the prefetch option for the whole organisation and hands it to the browser', function (): void {
    $owner = Membership::factory()->owner()->create();
    $this->actingAs($owner->user)->get('/settings/speed')->assertOk()->assertSee(__('speed.measured'));

    $this->post('/settings/speed/options', ['prefetch' => 'strong'])->assertRedirect('/settings/speed');
    $this->post('/settings/speed/options', ['prefetch' => 'turbo'])->assertSessionHasErrors('prefetch');

    expect(SpeedOptions::of($owner->organization->fresh())['prefetch'])->toBe('strong');
    $this->get('/')->assertSee('data-prefetch="strong"', false);
});

it('does not count a prefetched page as opened (point 164.8)', function (): void {
    $owner = Membership::factory()->owner()->create();
    $this->actingAs($owner->user)->get('/inbox', ['X-DocHamster-Prefetch' => '1'])->assertOk();
    $this->get('/inbox', ['Sec-Purpose' => 'prefetch'])->assertOk();
    expect($owner->fresh()->seen_at)->toBeNull();

    $this->get('/inbox')->assertOk();
    expect($owner->fresh()->seen_at)->not->toBeNull();
});

it('answers the self tests and keeps them from others', function (): void {
    $owner = Membership::factory()->owner()->create();
    $this->actingAs($owner->user)->getJson('/settings/speed/db')->assertOk()->assertJsonStructure(['ms']);
    expect(strlen((string) $this->get('/settings/speed/download')->assertOk()->getContent()))->toBe(SpeedController::DOWNLOAD_BYTES);

    $member = Membership::factory()->create(['org_id' => $owner->org_id]);
    $this->actingAs($member->user)->get('/settings/speed')->assertForbidden();
});
