<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Devices\DeviceKey;
use App\Domain\Org\CurrentOrg;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Geräte (points 152, A329, A330): each person's upload keys for an iPhone shortcut or
 * a program. The key shows once, right after "hinzufügen"; afterwards only its first characters.
 */
final class DeviceSettingsController
{
    public function index(Request $request, CurrentOrg $org): View
    {
        $fresh = $request->session()->get('device_key');

        return view('settings.devices', [
            'devices' => DeviceKey::query()->where('user_id', $org->membership()->user_id)->whereNull('revoked_at')->oldest()->get(),
            'fresh' => is_array($fresh) ? $fresh : null,
            'apiUrl' => route('api.upload'),
        ]);
    }

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(DeviceKey::KINDS)],
        ]);
        $userId = $org->membership()->user_id;
        [$device, $key] = DeviceKey::issue($userId, trim($data['name']), $data['kind']);
        $audit->record('device.key_created', orgId: $org->id(), userId: $userId, subject: $device, meta: ['kind' => $device->kind], ip: $request->ip());

        // Shown once: flashed for the next page only, never stored in plain anywhere else.
        return redirect()->route('devices.index')->with('device_key', ['id' => $device->id, 'key' => $key, 'kind' => $device->kind, 'name' => $device->name]);
    }

    /** Löschen: revoked at once; the row stays for the audit trail. */
    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $device = DeviceKey::query()->where('user_id', $userId)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $device->update(['revoked_at' => now()]);
        $audit->record('device.key_revoked', orgId: $org->id(), userId: $userId, subject: $device, ip: $request->ip());

        return redirect()->route('devices.index')->with('status', __('devices.deleted', ['name' => $device->name]));
    }
}
