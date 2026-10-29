<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * The two job queues (D-020). Only jobs on `Net` may talk to the outside
 * world (bank modules, mail); everything else runs on `Local` without
 * outbound network. Net job classes live in App\Jobs\Net – the arch test
 * in tests/Arch enforces that HTTP clients and mailers are used only there.
 */
enum QueueName: string
{
    case Local = 'local';
    case Net = 'net';

    /**
     * Order in which a cron-started worker drains the queues: `net` first,
     * because its jobs (token exchange, mail) are short and time-sensitive.
     */
    public static function workerOrder(): string
    {
        return self::Net->value.','.self::Local->value;
    }
}
