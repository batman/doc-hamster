<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Point 164.6: what makes every request of this server slower than it needs to be – with a tip each
 * (Einstellungen → Geschwindigkeit and `dochamster:doctor`). Never a failure: the app runs either way.
 */
final class SpeedCheck
{
    /** Median of SELECT 1 above this many milliseconds: the database is far away or busy. */
    public const float SLOW_DB_MS = 3.0;

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        $app = app();

        return [
            $this->opcache(),
            self::flag('speed_debug', config('app.debug') !== true, 'speed_debug_off', 'speed_debug_on'),
            self::flag('speed_config', $app->configurationIsCached(), 'speed_cached', 'speed_not_cached'),
            self::flag('speed_routes', $app->routesAreCached(), 'speed_cached', 'speed_not_cached'),
            self::flag('speed_events', $app->eventsAreCached(), 'speed_cached', 'speed_not_cached'),
            self::flag('speed_xdebug', ! extension_loaded('xdebug'), 'speed_xdebug_off', 'speed_xdebug_on'),
            $this->database(),
        ];
    }

    /** Median milliseconds of $times × SELECT 1 (null: no database). */
    public static function dbLatency(int $times = 10): ?float
    {
        try {
            $ms = [];
            for ($i = 0; $i < $times; $i++) {
                $start = hrtime(true);
                DB::select('select 1');
                $ms[] = (hrtime(true) - $start) / 1e6;
            }
        } catch (Throwable) {
            return null;
        }
        sort($ms);

        return $ms[intdiv(count($ms), 2)];
    }

    private function opcache(): CheckResult
    {
        if (PHP_SAPI === 'cli') {
            return new CheckResult('speed_opcache', CheckStatus::Warning, 'speed_opcache_cli');
        }
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $on = is_array($status) && ($status['opcache_enabled'] ?? false) === true;

        return new CheckResult('speed_opcache', $on ? CheckStatus::Ok : CheckStatus::Warning, $on ? 'speed_opcache_on' : 'speed_opcache_off');
    }

    private function database(): CheckResult
    {
        $ms = self::dbLatency();
        if ($ms === null) {
            return new CheckResult('speed_database', CheckStatus::Warning, 'speed_db_unreachable');
        }

        return new CheckResult('speed_database', $ms <= self::SLOW_DB_MS ? CheckStatus::Ok : CheckStatus::Warning,
            $ms <= self::SLOW_DB_MS ? 'speed_db_fast' : 'speed_db_slow', ['ms' => number_format($ms, 1, ',', '')]);
    }

    private static function flag(string $key, bool $good, string $ok, string $bad): CheckResult
    {
        return new CheckResult($key, $good ? CheckStatus::Ok : CheckStatus::Warning, $good ? $ok : $bad);
    }
}
