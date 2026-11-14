<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Devices\DeviceKey;
use App\Domain\Org\CurrentOrg;
use App\Domain\Payments\GiroCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Geräte (points 152, A329, A330): each person's upload keys for an iPhone shortcut or
 * a program. The key shows once, right after "hinzufügen" or "Neuen Schlüssel erzeugen"; afterwards only its first
 * characters.
 *
 * Point 201.1: the key no longer travels as a session flash (any other request – window prefetch, badge poll – ate it).
 * It waits a few minutes in the cache, encrypted, under a random token that only the redirect address carries; the
 * page shows it at once in an open dialog.
 */
final class DeviceSettingsController
{
    private const SHOW_MINUTES = 10;

    /**
     * Point 202: the ready-made iPhone shortcut "docHamster" – one action "Inhalte von URL abrufen" (POST, Formular,
     * field file = Kurzbefehleingabe, header Authorization), placeholders only (example.com, "Bearer SCHLUESSEL").
     * Apple's signature of this share expires 2027-12-07: check then whether the import still works, else share again.
     */
    public const SHORTCUT_URL = 'https://www.icloud.com/shortcuts/f3dc748281f5445fb2f7726c61311ee4';

    public function index(Request $request, CurrentOrg $org): View
    {
        $token = $request->query('shown');
        $fresh = is_string($token) && $token !== '' ? $this->shown($org->membership()->user_id, $token) : null;

        return view('settings.devices', [
            'devices' => DeviceKey::query()->where('user_id', $org->membership()->user_id)->whereNull('revoked_at')->oldest()->get(),
            'fresh' => is_array($fresh) ? $fresh : null,
            'apiUrl' => route('api.upload'),
            'shortcutUrl' => self::SHORTCUT_URL,
            'shortcutQr' => 'data:image/svg+xml;base64,'.base64_encode(GiroCode::svg(self::SHORTCUT_URL, 180)),
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

        return $this->show($device, $key);
    }

    /** Point 201.3: a new key for the same device; the old one stops working at once. */
    public function rekey(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $device = DeviceKey::query()->where('user_id', $userId)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $key = $device->renew();
        $audit->record('device.key_renewed', orgId: $org->id(), userId: $userId, subject: $device, ip: $request->ip());

        return $this->show($device, $key);
    }

    private function show(DeviceKey $device, string $key): RedirectResponse
    {
        $token = Str::random(32);
        Cache::put($this->cacheKey($device->user_id, $token), encrypt(['id' => $device->id, 'key' => $key, 'kind' => $device->kind, 'name' => $device->name]), now()->addMinutes(self::SHOW_MINUTES));

        return redirect()->route('devices.index', ['shown' => $token]);
    }

    /** @return array{id: string, key: string, kind: string, name: string}|null */
    private function shown(string $userId, string $token): ?array
    {
        $sealed = Cache::get($this->cacheKey($userId, $token));
        if (! is_string($sealed)) {
            return null;
        }
        $fresh = decrypt($sealed);
        if (! is_array($fresh)) {
            return null;
        }
        $part = static fn (string $name): string => is_string($fresh[$name] ?? null) ? $fresh[$name] : '';

        return $part('key') === '' ? null : ['id' => $part('id'), 'key' => $part('key'), 'kind' => $part('kind'), 'name' => $part('name')];
    }

    private function cacheKey(string $userId, string $token): string
    {
        return 'device-key:'.$userId.':'.hash('sha256', $token);
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
