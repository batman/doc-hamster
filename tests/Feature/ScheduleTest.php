<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * @return list<Event>
 */
function scheduledEvents(): array
{
    return array_values(app(Schedule::class)->events());
}

it('writes a cron heartbeat every minute', function (): void {
    $heartbeat = collect(scheduledEvents())->first(fn (Event $e): bool => $e->description === 'cron-heartbeat');

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *');
});

it('drains both queues in a short, valid worker run every minute', function (): void {
    $worker = collect(scheduledEvents())->first(fn (Event $e): bool => str_contains((string) $e->command, 'queue:work'));

    expect($worker)->not->toBeNull()
        ->and($worker->expression)->toBe('* * * * *')
        ->and($worker->command)
        ->toContain('--queue=net,local')
        ->toContain('--max-time=50')
        // queue:work rejects a value on this flag – the cron run would fail every minute.
        ->toContain('--stop-when-empty ')
        ->not->toContain('--stop-when-empty=');
});
