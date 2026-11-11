<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queue\CronClock;
use Illuminate\Http\JsonResponse;

/**
 * A69/A74: without cron the open app is the alarm clock. Each call works off
 * at most one queued job; with cron running it does nothing.
 */
final class QueueTickController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (CronClock::running()) {
            return response()->json(['cron' => true, 'waiting' => 0]);
        }
        if (CronClock::waiting() > 0) {
            CronClock::tick();
        }

        return response()->json(['cron' => false, 'waiting' => CronClock::waiting()]);
    }
}
