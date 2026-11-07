<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class LoginController extends Controller
{
    /** Throwaway hash for the dummy verify below, derived once per process. */
    private static ?string $dummyHash = null;

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        $username = mb_strtolower($data['username']);

        if (! Auth::attempt(['username' => $username, 'password' => $data['password']])) {
            // Auth::attempt() only hashes when the user exists and has a
            // password – without this, the response time reveals whether a
            // username exists (issue #6). The dummy verify costs one hash
            // with the configured driver and options, just like a wrong
            // password, so both failures take the same time. The extra
            // lookup runs only on the (rate-limited) failure path.
            if (! User::query()->where('username', $username)->whereNotNull('password_hash')->exists()) {
                Hash::check($data['password'], self::dummyHash());
            }

            return back()->withErrors(['username' => __('auth.failed')])->onlyInput('username');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * A throwaway hash with the cost of a real password hash. Derived from
     * the configured hasher instead of hard-coded, so the dummy verify
     * always costs one hash – in every environment. The first call per
     * worker process pays one extra hash for deriving it; steady state is
     * exactly one verify, like a wrong password.
     */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(32));
    }
}
