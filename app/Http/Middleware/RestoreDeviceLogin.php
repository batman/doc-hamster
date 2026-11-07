<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Device;
use App\Domain\Auth\DeviceLogin;
use App\Domain\Auth\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A connected phone stays signed in (PLAN §11.1 point 5): when its short
 * session has ended, the device cookie opens a new one without asking. A
 * session that was opened by a device ends as soon as that device is
 * removed in "Handy verbinden".
 */
final readonly class RestoreDeviceLogin
{
    public function __construct(private DeviceLogin $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $refresh = null;
        $user = Auth::user();
        $deviceId = $request->session()->get(DeviceLogin::SESSION_KEY);

        if ($user instanceof User && is_string($deviceId)) {
            $device = Device::query()->whereKey($deviceId)->whereNull('revoked_at')->first();
            if ($device === null) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withCookie($this->devices->forget($request));
            }
            if ($this->devices->touch($device, $request)) {
                $refresh = $this->devices->token($request);
            }
        } elseif (! $user instanceof User) {
            $device = $this->devices->find($request);
            $owner = $device?->user;
            if ($device !== null && $owner instanceof User) {
                Auth::login($owner);
                $request->session()->regenerate();
                $request->session()->put(DeviceLogin::SESSION_KEY, $device->id);
                $this->devices->touch($device, $request);
                $refresh = $this->devices->token($request);
            }
        }

        $response = $next($request);

        if ($refresh !== null) {
            $response->headers->setCookie($this->devices->cookie($request, $refresh));
        }

        return $response;
    }
}
