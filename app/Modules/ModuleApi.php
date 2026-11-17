<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * What docHamster promises modules (D-041, docs/modules/BUILDING.md). VERSION goes up only when a promise breaks;
 * a module states the version it was built for ("api" in its Steckbrief) and is refused or switched off when it
 * does not fit (A65, A66).
 */
final class ModuleApi
{
    public const int VERSION = 1;

    /** The rights a module can ask for (F14 a); the owner grants them at install, an update asks for new ones again. */
    public const array RIGHTS = ['documents_read', 'documents_write', 'bookings_read', 'bookings_write', 'network'];

    /** The events a module can listen to (ModuleContext::on). */
    public const array EVENTS = ['document.created', 'document.recognised', 'booking.created', 'link.confirmed'];

    /** Which right an event needs. */
    public const array EVENT_RIGHT = ['document.created' => 'documents_read', 'document.recognised' => 'documents_read', 'booking.created' => 'bookings_read', 'link.confirmed' => 'bookings_read'];
}
