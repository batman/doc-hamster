<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Auth\User;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Role;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Modules (D-041): the registry and the plug points as singletons, the owner-only Gate, the core events modules can
 * listen to, and the start of the switched-on modules once everything else is booted.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstalledModules::class);
        $this->app->scoped(ModuleConnections::class);
    }

    public function boot(): void
    {
        // F15: only the owner (the administrator) installs, updates and removes modules.
        Gate::define('modules.manage', static fn (User $user): bool => app(CurrentOrg::class)->isSet() && app(CurrentOrg::class)->role() === Role::Owner);

        $emit = static fn (string $event, object $subject) => app(ModuleConnections::class)->emit($event, $subject);
        // Documents in private folders never reach a module (A69).
        $open = static fn (Document $d): bool => $d->folder_id === null || ! in_array($d->folder_id, Folder::privateBranch($d->org_id), true);
        $heard = static fn (string $event): bool => app(ModuleConnections::class)->hears($event);
        Document::created(static function (Document $d) use ($emit, $open, $heard): void {
            if ($heard('document.created') && $open($d)) {
                $emit('document.created', $d);
            }
        });
        Document::updated(static function (Document $d) use ($emit, $open, $heard): void {
            if ($d->wasChanged('ocr_status') && $d->getAttribute('ocr_status') === 'done' && $heard('document.recognised') && $open($d)) {
                $emit('document.recognised', $d);
            }
        });
        Transaction::created(static fn (Transaction $t) => $emit('booking.created', $t));
        TransactionMatch::saved(static function (TransactionMatch $m) use ($emit): void {
            if ($m->state === TransactionMatch::CONFIRMED && ($m->wasRecentlyCreated || $m->wasChanged('state'))) {
                $emit('link.confirmed', $m);
            }
        });

        $this->app->booted(static fn () => app(ModuleLoader::class)->boot());
    }
}
