<?php

declare(strict_types=1);

use App\Domain\Log\LogEntry;
use App\Domain\Ocr\OcrLog;
use App\Infrastructure\Environment\NativeSystemProbe;
use App\Queue\QueueName;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Cron calls `php artisan schedule:run` every minute (D-010). No permanent
| worker is required: each minute a short worker run drains the queues and
| stops before the next cron tick. A systemd/Supervisor worker is optional.
*/

// Lets `dochamster:doctor` see that cron is running.
Schedule::call(fn () => Cache::forever(NativeSystemProbe::HEARTBEAT_CACHE_KEY, time()))
    ->name('cron-heartbeat')
    ->everyMinute();

// Plain string on purpose: the array form renders flags as `--stop-when-empty=1`,
// which queue:work rejects because the option takes no value.
Schedule::command('queue:work --queue='.QueueName::workerOrder().' --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    // Lock expires after 2 minutes, so a crashed run can't block the queue for long.
    ->withoutOverlapping(2);

// Point 61: the OCR log keeps 30 days (writing also prunes now and then, for servers without cron).
Schedule::call(static fn () => OcrLog::prune())
    ->name('ocr-log-prune')
    ->daily();

// A228: the logbooks "Allgemein" and "Updates" keep 12 months (writing also prunes now and then).
Schedule::call(static fn () => LogEntry::prune())
    ->name('log-prune')
    ->daily();
