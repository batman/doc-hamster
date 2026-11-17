<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * The one class every docHamster module ships (D-041): its `entry` in dochamster-module.json implements this.
 * docHamster calls register() once per request with the module's own context – the only door into the core
 * (docs/modules/BUILDING.md). Throwing here switches the module off; docHamster keeps running (A66).
 */
interface Module
{
    public function register(ModuleContext $context): void;
}
