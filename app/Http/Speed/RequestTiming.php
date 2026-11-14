<?php

declare(strict_types=1);

namespace App\Http\Speed;

/**
 * Point 164.1: what one request spent where – database time and query count
 * (fed by DB::listen in AppServiceProvider), and the view rendering from the
 * first view created on. One instance for the app; the ServerTiming
 * middleware starts it again for every request.
 */
final class RequestTiming
{
    private float $start;

    private float $dbMs = 0.0;

    private int $queries = 0;

    private ?float $viewStart = null;

    private float $dbMsBeforeView = 0.0;

    private ?float $end = null;

    public function __construct()
    {
        $this->start = microtime(true);
    }

    public function start(float $at): void
    {
        $this->start = $at;
        $this->dbMs = 0.0;
        $this->queries = 0;
        $this->viewStart = null;
        $this->dbMsBeforeView = 0.0;
        $this->end = null;
    }

    /** The response is ready: total and view time stop here (sending and the after-work don't count). */
    public function finish(): void
    {
        $this->end = microtime(true);
    }

    public function query(float $ms): void
    {
        $this->dbMs += $ms;
        $this->queries++;
    }

    public function viewCreated(): void
    {
        if ($this->viewStart === null) {
            $this->viewStart = microtime(true);
            $this->dbMsBeforeView = $this->dbMs;
        }
    }

    public function queries(): int
    {
        return $this->queries;
    }

    public function dbMs(): float
    {
        return $this->dbMs;
    }

    public function totalMs(): float
    {
        return (($this->end ?? microtime(true)) - $this->start) * 1000;
    }

    /** Rendering time without the queries the templates ran themselves (those count as db). */
    public function viewMs(): float
    {
        if ($this->viewStart === null) {
            return 0.0;
        }

        return max(0.0, (($this->end ?? microtime(true)) - $this->viewStart) * 1000 - ($this->dbMs - $this->dbMsBeforeView));
    }

    /** The Server-Timing header value (shown by the browser's DevTools under Timing). */
    public function header(): string
    {
        return sprintf(
            'total;dur=%.1f, db;dur=%.1f;desc="%d queries", queries;desc="%d", view;dur=%.1f',
            $this->totalMs(), $this->dbMs, $this->queries, $this->queries, $this->viewMs(),
        );
    }
}
