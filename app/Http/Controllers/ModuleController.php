<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Jobs\Net\InstallModule;
use App\Jobs\Net\ModuleSource;
use App\Modules\InstalledModules;
use App\Modules\Manifest;
use App\Modules\ModuleInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Module on the Software page (D-041, points 10–19), owner only: look up a GitHub project or the partner list (only
 * on a click), install with the rights the module asks for (after the "fremder Code" notice and a fresh password),
 * update (new rights are asked again), switch off and on, remove – with or without its data (F18 a).
 */
final class ModuleController
{
    public function __construct(private readonly InstalledModules $installed) {}

    /** "Suchen": the Steckbrief of one project, shown in the Module card to install. */
    public function find(Request $request, ModuleSource $source): RedirectResponse
    {
        $repo = ModuleSource::repo((string) $request->validate(['address' => ['required', 'string', 'max:200']])['address']);
        if ($repo === null) {
            return $this->back(error: __('modules.bad_address'));
        }
        try {
            $found = [['source' => $repo, 'manifest' => $source->find($repo)->toArray()]];
        } catch (RuntimeException $e) {
            return $this->back(error: __('modules.not_found', ['address' => $repo]).' '.$e->getMessage());
        }

        $request->session()->put('modules.found', $found);

        return $this->back();
    }

    /** "Partner-Module anzeigen" (F20): the modules of the public partner list. */
    public function partners(Request $request, ModuleSource $source): RedirectResponse
    {
        try {
            $found = array_map(static fn (array $f): array => ['source' => $f['source'], 'manifest' => $f['manifest']->toArray()], $source->partners());
        } catch (RuntimeException $e) {
            return $this->back(error: $e->getMessage());
        }

        if ($found === []) {
            return $this->back(status: __('modules.no_partners'));
        }
        $request->session()->put('modules.found', $found);

        return $this->back();
    }

    public function install(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', 'string', 'max:200'], 'key' => ['required', 'string', 'max:40'], 'rights' => ['nullable', 'array'], 'rights.*' => ['string'], 'accept' => ['accepted']]);
        $repo = ModuleSource::repo($data['source']);
        if ($repo === null) {
            return $this->back(error: __('modules.bad_address'));
        }

        return $this->run($request, $repo, $data['key'], array_values($data['rights'] ?? []), $audit, $org, 'module.installed');
    }

    /** "Aktualisieren": from the same project; the rights it asks for now must have been granted (else asked again). */
    public function update(Request $request, string $key, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $entry = $this->installed->find($key) ?? abort(404);
        $rights = $entry['rights'];
        if ($request->boolean('accept')) {
            $rights = array_values(array_unique([...$rights, ...array_map('strval', (array) $request->input('rights', []))]));
        }

        return $this->run($request, $entry['source'], $key, $rights, $audit, $org, 'module.updated');
    }

    public function toggle(string $key): RedirectResponse
    {
        $entry = $this->installed->find($key) ?? abort(404);
        $entry['enabled'] ? $this->installed->switchOff($key) : $this->installed->switchOn($key);

        return $this->back(status: __($entry['enabled'] ? 'modules.switched_off' : 'modules.switched_on', ['name' => $entry['manifest']['name'] ?? $key]));
    }

    public function remove(Request $request, string $key, ModuleInstaller $installer, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $entry = $this->installed->find($key) ?? abort(404);
        $installer->remove($key, $request->boolean('with_data'));
        $audit->record('module.removed', orgId: $org->id(), userId: $org->membership()->user_id, ip: $request->ip(), meta: ['module' => $key, 'with_data' => $request->boolean('with_data')]);

        return $this->back(status: __('modules.removed', ['name' => $entry['manifest']['name'] ?? $key]));
    }

    /** @param list<string> $rights */
    private function run(Request $request, string $repo, string $key, array $rights, AuditLog $audit, CurrentOrg $org, string $event): RedirectResponse
    {
        set_time_limit(900);
        try {
            /** @var Manifest $manifest */
            // The net job runs right here in the request (like InstallExtension); its result comes back.
            $manifest = app()->call([new InstallModule($repo, $key, $rights), 'handle']);
        } catch (Throwable $e) {
            return $this->back(error: __('modules.failed', ['reason' => mb_substr($e->getMessage(), 0, 300)]));
        }
        $audit->record($event, orgId: $org->id(), userId: $org->membership()->user_id, ip: $request->ip(), meta: ['module' => $key, 'version' => $manifest->version, 'source' => $repo]);
        $request->session()->forget('modules.found');

        return $this->back(status: __('modules.installed', ['name' => $manifest->name, 'version' => $manifest->version]));
    }

    private function back(?string $status = null, ?string $error = null): RedirectResponse
    {
        $to = redirect()->to(route('updates.show').'#modules');

        return $status !== null ? $to->with('status', $status) : ($error !== null ? $to->with('error', $error) : $to);
    }
}
