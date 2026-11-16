<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Api\AccessKey;
use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mein Konto → Zugangsschlüssel (D-040): a person's own keys for the interface (api/v1), "Nur lesen" or
 * "Lesen + Verknüpfen". Making and removing one needs a fresh confirmation (point 212). The key shows once, right after
 * it is made – like the device keys (point 201.1) it waits a few minutes in the cache, encrypted, under a random token
 * that only the redirect address carries; afterwards only its first characters show.
 */
final class AccessKeyController
{
    private const SHOW_MINUTES = 10;

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scope' => ['required', Rule::in(AccessKey::SCOPES)],
        ]);
        $userId = $org->membership()->user_id;
        [$access, $key] = AccessKey::issue($userId, trim($data['name']), $data['scope']);
        $audit->record('api.key_created', orgId: $org->id(), userId: $userId, subject: $access, meta: ['scope' => $access->scope], ip: $request->ip());

        $token = Str::random(32);
        Cache::put(self::cacheKey($userId, $token), encrypt(['name' => $access->name, 'key' => $key]), now()->addMinutes(self::SHOW_MINUTES));

        return redirect()->route('account.show', ['key' => $token]);
    }

    /** Removing a key stops it at once; the row stays for the audit trail. */
    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $access = AccessKey::query()->where('user_id', $userId)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $access->update(['revoked_at' => now()]);
        $audit->record('api.key_revoked', orgId: $org->id(), userId: $userId, subject: $access, ip: $request->ip());

        return redirect()->route('account.show')->with('status', __('account.keys.deleted', ['name' => $access->name]));
    }

    /**
     * The page's part: the person's working keys and, right after "anlegen", the new key once.
     *
     * @return array{accessKeys: Collection<int, AccessKey>, freshKey: array{name: string, key: string}|null, apiUrl: string}
     */
    public static function pageData(Request $request, string $userId): array
    {
        $token = $request->query('key');
        $fresh = null;
        if (is_string($token) && $token !== '') {
            $sealed = Cache::get(self::cacheKey($userId, $token));
            $open = is_string($sealed) ? decrypt($sealed) : null;
            if (is_array($open) && is_string($open['key'] ?? null) && is_string($open['name'] ?? null)) {
                $fresh = ['name' => $open['name'], 'key' => $open['key']];
            }
        }

        return [
            'accessKeys' => AccessKey::query()->where('user_id', $userId)->whereNull('revoked_at')->oldest()->get(),
            'freshKey' => $fresh,
            'apiUrl' => route('api.v1.me'),
        ];
    }

    private static function cacheKey(string $userId, string $token): string
    {
        return 'access-key:'.$userId.':'.hash('sha256', $token);
    }
}
