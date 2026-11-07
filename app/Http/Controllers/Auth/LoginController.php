<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Device;
use App\Domain\Auth\DeviceLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);

        if (! Auth::attempt(['username' => mb_strtolower($data['username']), 'password' => $data['password']])) {
            return back()->withErrors(['username' => __('auth.failed')])->onlyInput('username');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /** Signing out on a connected phone also ends its device login; it has to be connected again. */
    public function logout(Request $request, DeviceLogin $devices): RedirectResponse
    {
        $deviceId = $request->session()->get(DeviceLogin::SESSION_KEY);
        if (is_string($deviceId)) {
            Device::query()->whereKey($deviceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withCookie($devices->forget($request));
    }
}
