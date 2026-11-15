<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\MailReady;
use App\Domain\Auth\OneTimePassword;
use App\Domain\Auth\User;
use App\Jobs\Net\SendPasswordResetLink;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * "Passwort vergessen?" (points 210.3, 215): with mail configured, anyone with a stored e-mail address gets Laravel's
 * reset link (sent by a `net` job after the response, D-020); without mail an Admin makes a new one-time password.
 */
final class PasswordResetController
{
    public function forgot(): View
    {
        abort_unless(MailReady::ready(), 404);

        return view('auth.forgot');
    }

    /** Always the same answer, whether the address exists or not; the mail leaves after the response (net job). */
    public function send(Request $request): RedirectResponse
    {
        abort_unless(MailReady::ready(), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        $broker = Password::broker();
        if ($user !== null && $broker instanceof PasswordBroker && $user->memberships()->whereNull('revoked_at')->exists()) {
            $token = $broker->createToken($user);
            SendPasswordResetLink::dispatchAfterResponse((string) $user->email, route('password.reset', ['token' => $token, 'email' => $user->email]));
        }

        return redirect()->route('login')->with('status', __('auth.forgot.sent'));
    }

    public function resetForm(Request $request, string $token): View
    {
        abort_unless(MailReady::ready(), 404);

        return view('auth.reset', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request, AuditLog $audit): RedirectResponse
    {
        abort_unless(MailReady::ready(), 404);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        if ($user === null) {
            return back()->withErrors(['email' => __('auth.forgot.invalid')]);
        }
        $status = Password::broker()->reset(
            ['email' => $user->email, 'token' => $data['token'], 'password' => $data['password']],
            static function (User $user, string $password): void {
                OneTimePassword::replace($user, $password, null);
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __('auth.forgot.invalid')]);
        }
        $audit->record('auth.password_reset_by_mail', userId: $user->id, ip: $request->ip());

        return redirect()->route('login')->with('status', __('auth.forgot.done'));
    }
}
