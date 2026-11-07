<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Infrastructure\Crypto\IpHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The long-lived login of a connected phone (PLAN §11.1 point 3): a random
 * 256-bit token in an HttpOnly cookie, only its SHA-256 in `devices`. The
 * cookie lasts 180 days and is renewed on every use, so a phone that is used
 * now and then never has to be connected again. The short session is
 * restored from it silently (RestoreDeviceLogin).
 */
final readonly class DeviceLogin
{
    public const DAYS = 180;

    /** Session key: the device this session was opened with. */
    public const SESSION_KEY = 'device_id';

    public function __construct(private IpHasher $ipHasher) {}

    /**
     * @return array{0: Device, 1: string} the device and the token in plain text (only for the cookie)
     */
    public function issue(User $user, string $name, Request $request): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'user_agent' => $request->userAgent(),
            'token_hash' => self::hash($token),
            'token_family' => (string) Str::ulid(),
            'last_used_at' => now(),
            'last_ip_hash' => $this->ipHasher->hash((string) $request->ip()),
        ]);

        return [$device, $token];
    }

    /** The device for this request's cookie, if it is valid. */
    public function find(Request $request): ?Device
    {
        $token = $request->cookie(self::cookieName($request));
        if (! is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return Device::query()
            ->where('token_hash', self::hash($token))
            ->whereNull('revoked_at')
            ->where('last_used_at', '>', now()->subDays(self::DAYS))
            ->first();
    }

    /**
     * Marks the device as used, at most every few minutes to spare the database.
     *
     * @return bool true when it was marked (then the cookie is renewed as well)
     */
    public function touch(Device $device, Request $request): bool
    {
        if ($device->last_used_at !== null && $device->last_used_at->gt(now()->subMinutes(5))) {
            return false;
        }
        $device->forceFill([
            'last_used_at' => now(),
            'last_ip_hash' => $this->ipHasher->hash((string) $request->ip()),
        ])->save();

        return true;
    }

    public function cookie(Request $request, string $token): Cookie
    {
        return cookie(self::cookieName($request), $token, self::DAYS * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public function forget(Request $request): Cookie
    {
        return cookie()->forget(self::cookieName($request), '/', null);
    }

    /**
     * `__Host-` keeps the cookie to exactly this host over HTTPS (D-009). Browsers
     * refuse that prefix without HTTPS, so a plain-HTTP test install uses a plain name.
     *
     * @return non-empty-string
     */
    public static function cookieName(Request $request): string
    {
        return $request->isSecure() ? '__Host-dh_device' : 'dh_device';
    }

    /** The token from the cookie as it is sent again: refreshed for 180 more days. */
    public function token(Request $request): ?string
    {
        $token = $request->cookie(self::cookieName($request));

        return is_string($token) ? $token : null;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
