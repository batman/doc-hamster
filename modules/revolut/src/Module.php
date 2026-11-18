<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use App\Domain\Org\CurrentOrg;
use App\Modules\Module as DocHamsterModule;
use App\Modules\ModuleContext;
use DocHamsterModules\Revolut\Http\RevolutController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Revolut Business (point 37, D-016/D-021/D-041): the bookings of every Revolut Business account and the receipts in
 * the Revolut app come into docHamster – read only, through an API key of the Revolut API Console with a signing key.
 * Every 15 minutes and shortly after an upload; the first time only after the Probelauf was taken over (A98). Point 38:
 * with "Zahlungs-Entwürfe" on, a document offers "Zahlungs-Entwurf bei Revolut" – sent only by a person in Revolut.
 */
final class Module implements DocHamsterModule
{
    public function register(ModuleContext $context): void
    {
        $context->routes(static function (): void {
            Route::controller(RevolutController::class)->group(static function (): void {
                Route::get('settings', 'show')->name('settings');
                Route::post('settings/environment', 'environment')->name('environment');
                Route::post('settings/keygen', 'keygen')->name('keygen');
                Route::post('settings/key', 'key')->name('key');
                Route::post('settings/map', 'map')->name('map');
                Route::post('settings/probe', 'probe')->name('probe');
                Route::post('settings/accept', 'accept')->name('accept');
                Route::post('settings/sync', 'sync')->middleware('throttle:10,1')->name('sync');
                Route::post('settings/drafts', 'drafts')->name('drafts');
                Route::get('documents/{id}/draft', 'draftForm')->name('draft');
                Route::post('documents/{id}/draft', 'draftCreate')->middleware('throttle:20,1')->name('draft.create');
            });
        });
        $context->settingsPage('Revolut Business', __('m-revolut::texts.page_text'), 'building-bank', 'settings');
        // Point 38: only while "Zahlungs-Entwürfe" is on and for the owner (asked when the ⋯ menu is drawn).
        $context->documentAction(__('m-revolut::texts.draft_action'), 'send', 'draft', static fn (): bool => self::draftsOn() && Gate::allows('modules.manage'));

        // Every 15 minutes, once the Probelauf was taken over.
        $context->every(15, static function () use ($context): void {
            if (self::confirmed()) {
                $context->netJob(new RevolutJob('sync'));
            }
        });
        // Shortly after an upload a booking may be there for it: fetch, at most every 2 minutes.
        $context->on('document.created', static function () use ($context): void {
            if (! Sync::$running && self::confirmed() && Cache::add('m-revolut:after-upload', true, 120)) {
                $context->netJob((new RevolutJob('sync'))->delay(now()->addMinute()));
            }
        });
    }

    private static function row(): ?object
    {
        $org = app(CurrentOrg::class);
        try {
            return $org->isSet() ? DB::table('m_revolut_settings')->where('org_id', $org->id())->first(['confirmed_at', 'drafts']) : null;
        } catch (Throwable) {
            return null; // tables not there yet (during the install)
        }
    }

    private static function confirmed(): bool
    {
        return (self::row()->confirmed_at ?? null) !== null;
    }

    private static function draftsOn(): bool
    {
        return (bool) (self::row()->drafts ?? false);
    }
}
