<?php

declare(strict_types=1);

namespace App\Queue;

use App\Infrastructure\Environment\NativeSystemProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Is cron running (A69/A74)? `schedule:run` writes a heartbeat every minute
 * (routes/console.php). Without it the open browser is the alarm clock: it
 * calls the tick endpoint, which works off one queued job per call.
 */
final class CronClock
{
    /** Heartbeat older than this = no cron. */
    public const int MAX_AGE_SECONDS = 180;

    /** One tick at a time across tabs and users; long enough for one OCR job. */
    private const int TICK_LOCK_SECONDS = 300;

    public static function lastRun(): ?int
    {
        try {
            $value = Cache::memo()->get(NativeSystemProbe::HEARTBEAT_CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_int($value) ? $value : null;
    }

    public static function running(): bool
    {
        $last = self::lastRun();

        return $last !== null && time() - $last <= self::MAX_AGE_SECONDS;
    }

    /** Jobs waiting on the worker's queues (0 on the sync driver). */
    public static function waiting(): int
    {
        $waiting = 0;
        foreach (explode(',', QueueName::workerOrder()) as $queue) {
            $waiting += Queue::size($queue);
        }

        return $waiting;
    }

    /** Works off at most one job, like one cron worker step; false when another tick runs. */
    public static function tick(): bool
    {
        $lock = Cache::lock('queue.tick', self::TICK_LOCK_SECONDS);
        if (! $lock->get()) {
            return false;
        }
        try {
            set_time_limit(self::TICK_LOCK_SECONDS);
            ignore_user_abort(true);
            // --timeout=0: no pcntl alarm inside a web request; the jobs limit their own programs.
            Artisan::call('queue:work', [
                '--queue' => QueueName::workerOrder(),
                '--once' => true,
                '--stop-when-empty' => true,
                '--tries' => 3,
                '--timeout' => 0,
            ]);
        } finally {
            $lock->release();
        }

        return true;
    }
}
