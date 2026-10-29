<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Infrastructure\Crypto\IpHasher;
use App\Infrastructure\Environment\NativeSystemProbe;
use App\Infrastructure\Environment\SystemProbe;
use App\Infrastructure\Session\NoIpDatabaseSessionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SystemProbe::class, NativeSystemProbe::class);

        // Active organisation per request/job (D-008), reset between them.
        $this->app->scoped(CurrentOrg::class);

        // IP hashes are keyed with the app key (PLAN §11.3).
        $this->app->singleton(IpHasher::class, static function (): IpHasher {
            $key = config('app.key');

            if (! is_string($key) || $key === '') {
                throw new MissingAppKeyException;
            }

            return new IpHasher($key);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Org-wide abilities from the permission matrix (PLAN §2.1), e.g. Gate::allows('members.manage').
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, static fn (User $user): bool => app(CurrentOrg::class)->isSet()
                && app(CurrentOrg::class)->membership()->user_id === $user->id
                && app(CurrentOrg::class)->allows($permission));
        }

        // Replaces Laravel's `database` session driver: same table, no client IP.
        Session::extend('database', static function (Application $app): NoIpDatabaseSessionHandler {
            $connection = config('session.connection');
            $table = config('session.table');
            $lifetime = config('session.lifetime');

            return new NoIpDatabaseSessionHandler(
                $app->make('db')->connection(is_string($connection) ? $connection : null),
                is_string($table) ? $table : 'sessions',
                is_numeric($lifetime) ? (int) $lifetime : 120,
                $app,
            );
        });
    }
}
