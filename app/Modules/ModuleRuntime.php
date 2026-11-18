<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * What a module's background work needs (A97): its own context inside a queued job, and running as the owner of the
 * installation (D-042: one organisation) – jobs and cron have no signed-in person. Also runs the modules' tasks
 * (ModuleContext::every) from docHamster's cron once a minute; a task that throws switches its module off (A66).
 */
final class ModuleRuntime
{
    public static function context(string $module): ModuleContext
    {
        return app(ModuleConnections::class)->contexts[$module] ?? throw new RuntimeException('Module '.$module.' is not running.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function asOwner(callable $work): mixed
    {
        $org = app(CurrentOrg::class);
        if ($org->isSet()) {
            return $work();
        }
        $organization = Organization::query()->oldest()->first() ?? throw new RuntimeException('No organisation.');
        $owner = Membership::query()->withoutGlobalScopes()->where('org_id', $organization->id)->whereNull('revoked_at')->get()
            ->first(static fn (Membership $m): bool => $m->role === Role::Owner) ?? throw new RuntimeException('No owner.');
        $org->set($owner);
        try {
            return $work();
        } finally {
            $org->clear();
        }
    }

    /** Cron, every minute: each module task whose time has come. */
    public static function tick(): void
    {
        foreach (app(ModuleConnections::class)->tasks as $i => ['module' => $module, 'minutes' => $minutes, 'task' => $task]) {
            $key = 'module-task:'.$module.':'.$i;
            if ((int) Cache::get($key, 0) > time() - $minutes * 60 + 30) {
                continue;
            }
            Cache::forever($key, time());
            try {
                self::asOwner($task);
            } catch (Throwable $e) {
                Log::warning('Module '.$module.' task failed: '.$e->getMessage());
                app(InstalledModules::class)->switchOff($module, __('modules.crashed', ['reason' => $e->getMessage()]));
            }
        }
    }
}
