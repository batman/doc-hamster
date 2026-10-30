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
use App\Setup\EnvFile;
use App\Setup\SetupCode;
use App\Setup\SetupMode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Setup wizard (D-024): `.env` and the code file outside the web root.
        $this->app->bind(EnvFile::class, fn (): EnvFile => new EnvFile($this->app->environmentFilePath()));
        $this->app->bind(SetupCode::class, static fn (): SetupCode => new SetupCode(storage_path('app/private/setup-code.txt')));

        // Only for web requests: on the console, `dochamster:setup` does the same job.
        if (config('dochamster.installed') !== true && ! $this->app->runningInConsole()) {
            SetupMode::apply($this->app->make(EnvFile::class), base_path('.env.example'));
        }

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
        // A local reverse proxy (e.g. nginx in front of Apache on Plesk) terminates TLS; trust its X-Forwarded-*
        // headers so scheme and host are right. Set here, not in bootstrap/app.php, where .env isn't loaded yet.
        $proxies = config('dochamster.trusted_proxies');
        TrustProxies::at(array_values(array_filter(array_merge(
            ['127.0.0.1', '::1'],
            array_map('trim', explode(',', is_string($proxies) ? $proxies : '')),
        ))));

        // Brute-force protection (PLAN §11.2). Keys hold the IP only as HMAC, the cache stores them.
        RateLimiter::for('login', static function (Request $request): array {
            $ip = app(IpHasher::class)->hash((string) $request->ip());
            $username = mb_strtolower((string) $request->input('username'));

            return [
                Limit::perMinute(5)->by('login:'.$username.'|'.$ip),
                Limit::perMinute(20)->by('login-ip:'.$ip),
                Limit::perHour(30)->by('login-user:'.$username),
            ];
        });
        RateLimiter::for('pairing', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('pairing:'.app(IpHasher::class)->hash((string) $request->ip())));
        RateLimiter::for('setup', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by('setup:'.app(IpHasher::class)->hash((string) $request->ip())));

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
