<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\MailReady;
use App\Domain\Auth\RecentConfirmation;
use App\Domain\Auth\User;
use App\Domain\Org\PersonAccess;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class LoginController extends Controller
{
    /** Points 210, 215: after this many failed sign-ins of one user name it is locked for LOCK_SECONDS (on top of the rate limit) – for everyone. */
    private const int LOCK_AFTER = 10;

    private const int LOCK_SECONDS = 900;

    public function show(): View
    {
        // Points 210.3, 215: "Passwort vergessen?" only where mail works (for everyone with an e-mail).
        return view('auth.login', ['forgot' => MailReady::ready()]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        $username = mb_strtolower($data['username']);
        $lock = 'login-lock:'.hash('sha256', $username);
        if (RateLimiter::tooManyAttempts($lock, self::LOCK_AFTER)) {
            return back()->withErrors(['username' => __('auth.locked', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($lock) / 60))])])->onlyInput('username');
        }
        $known = User::query()->where('username', $username)->first();

        // "Angemeldet bleiben" keeps the login for 30 days on this device (A11).
        Auth::guard('web')->setRememberDuration(60 * 24 * 30);
        if (! Auth::attempt(['username' => $username, 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($lock, self::LOCK_SECONDS);
            // Auth::attempt() only hashes when the user exists and has a
            // password – without this, the response time reveals whether a
            // username exists (issue #6). The dummy verify costs one hash
            // with the configured driver and options, just like a wrong
            // password, so both failures take the same time.
            if ($known?->password_hash === null) {
                Hash::check($data['password'], self::dummyHash());
            }

            return back()->withErrors(['username' => __('auth.failed')])->onlyInput('username');
        }

        RateLimiter::clear($lock);
        // Points 218, 223: paused, not yet or no longer – refused with a clear message.
        $user = Auth::user();
        $refusal = $user instanceof User ? PersonAccess::refusalFor($user) : null;
        if ($refusal !== null) {
            Auth::guard('web')->logout();

            return back()->withErrors(['username' => $refusal])->onlyInput('username');
        }

        $request->session()->regenerate();
        // Point 212: the sign-in itself counts as a fresh confirmation (five minutes without a second ask).
        RecentConfirmation::mark($request->session());
        // Point 215: a one-time password is replaced first (Mein Konto → Passwort).
        if ($user instanceof User && $user->must_change_password) {
            $request->session()->put(AccountController::ONETIME, true);

            return redirect()->route('account.password');
        }

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
     * always costs one hash – in every environment. It is cached across
     * requests (PHP-FPM forgets statics after each request), keyed by the
     * hashing config so a changed cost setting derives a fresh one. Only the
     * very first unknown-user failure pays the extra hash for deriving it.
     */
    private static function dummyHash(): string
    {
        $key = 'auth.dummy-hash.'.hash('sha256', serialize(config('hashing')));

        return (string) Cache::rememberForever($key, fn (): string => Hash::make(Str::random(32)));
    }
}
