<?php

declare(strict_types=1);

use App\Domain\Backup\Backup;
use App\Domain\Documents\TrashSweeper;
use App\Domain\Log\LogEntry;
use App\Domain\Mail\MailFetch;
use App\Domain\Ocr\OcrLog;
use App\Domain\Optimize\OptimizeQueue;
use App\Infrastructure\Environment\NativeSystemProbe;
use App\Modules\ModuleRuntime;
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

// Point 207: the Papierkorb empties itself – emptied documents after their undo period, the rest after the organisation's setting.
Schedule::call(static fn () => app(TrashSweeper::class)->run())
    ->name('trash-sweep')
    ->daily();

// Point 206 (D-023): finished backups stay 24 hours, then their files go (the backup page also prunes).
Schedule::call(static fn () => Backup::prune())
    ->name('backup-prune')
    ->hourly();

// Point 236: "Dokumentgröße optimieren" works off the older files by itself while it is on, a few at a time (low queue).
Schedule::call(static fn () => OptimizeQueue::sweep())
    ->name('optimize-sweep')
    ->everyFiveMinutes();

// A128: the Mail-Eingang fetches its mailbox every few minutes (also without cron when the Posteingang opens).
Schedule::call(static fn () => MailFetch::schedule())
    ->name('mail-fetch')
    ->everyMinute();

// A97: the modules' own tasks (e.g. Revolut fetching its bookings), each at its own interval.
Schedule::call(static fn () => ModuleRuntime::tick())
    ->name('module-tasks')
    ->everyMinute();
