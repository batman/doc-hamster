<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Environment\Doctor;
use App\Infrastructure\Environment\SystemProbe;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * `GET /health` for uptime monitoring (PLAN §12): database reachable, storage
 * writable, background worker active. Public, so it answers only ok/fail per
 * check – no versions, paths or queue numbers. 503 if anything fails.
 */
final class HealthController
{
    public function __invoke(SystemProbe $probe): JsonResponse
    {
        $checks = [
            'database' => $this->databaseReachable($probe),
            'storage' => is_writable(storage_path('app')),
            'worker' => $this->workerActive($probe),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'fail',
            'checks' => array_map(fn (bool $passed): string => $passed ? 'ok' : 'fail', $checks),
        ], $ok ? 200 : 503);
    }

    private function databaseReachable(SystemProbe $probe): bool
    {
        try {
            $probe->databaseVersion();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function workerActive(SystemProbe $probe): bool
    {
        $last = $probe->lastCronHeartbeat();

        return $last !== null && $probe->now() - $last <= Doctor::MAX_HEARTBEAT_AGE_SECONDS;
    }
}
