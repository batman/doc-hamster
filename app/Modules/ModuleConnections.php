<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * What the installed modules plugged in during this request (D-041, F17 a: they only add): main menu entries,
 * Einstellungen pages, ⋯ entries of documents and bookings, and event listeners. Filled by ModuleContext, read by
 * the layouts. A listener that throws switches its module off (A66); the core action goes on.
 */
final class ModuleConnections
{
    /** @var list<array{module: string, label: string, icon: string, route: string}> */
    public array $menu = [];

    /** @var list<array{module: string, title: string, text: string, icon: string, route: string}> */
    public array $settings = [];

    /** @var list<array{module: string, label: string, icon: string, route: string}> */
    public array $documentActions = [];

    /** @var list<array{module: string, label: string, icon: string, route: string}> */
    public array $bookingActions = [];

    /** @var array<string, list<array{module: string, listener: callable}>> */
    private array $listeners = [];

    public function listen(string $event, string $module, callable $listener): void
    {
        $this->listeners[$event][] = ['module' => $module, 'listener' => $listener];
    }

    /** Whether any module listens to the event (so the core does no extra work for nobody). */
    public function hears(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    /** Core places call this (ModuleServiceProvider's model events); every listener runs, a failing one switches its module off. */
    public function emit(string $event, object $subject): void
    {
        foreach ($this->listeners[$event] ?? [] as ['module' => $module, 'listener' => $listener]) {
            try {
                $listener($subject);
            } catch (Throwable $e) {
                Log::warning('Module '.$module.' failed on '.$event.': '.$e->getMessage());
                app(InstalledModules::class)->switchOff($module, __('modules.crashed', ['reason' => $e->getMessage()]));
            }
        }
    }

    /**
     * The entries whose route exists – for a layout.
     *
     * @param  list<array<string, string>>  $entries
     * @return list<array<string, string>>
     */
    public static function routed(array $entries): array
    {
        return array_values(array_filter($entries, static fn (array $e): bool => Route::has($e['route'])));
    }
}
