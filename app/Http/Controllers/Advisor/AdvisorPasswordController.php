<?php

declare(strict_types=1);

namespace App\Http\Controllers\Advisor;

use App\Domain\Advisor\AdvisorAccess;
use App\Domain\Advisor\AdvisorMail;
use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Auth\UserSessions;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Role;
use App\Jobs\Net\SendAdvisorResetLink;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The tax advisor's own password (point 210.2/210.3): after a one-time password he sets his own before anything else;
 * he may change it any time. With an e-mail stored and mail configured, "Passwort vergessen?" sends Laravel's reset
 * link – for tax advisors only (everyone else asks an Admin).
 */
final class AdvisorPasswordController
{
    public function edit(CurrentOrg $org): View
    {
        abort_unless($org->role() === Role::Accountant, 403);

        return view('advisor.password', ['forced' => AdvisorAccess::forMembership($org->membership())?->must_change_password === true]);
    }

    public function update(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        abort_unless($org->role() === Role::Accountant, 403);
        $data = $request->validate([
            'current' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current', PasswordRule::min(12)],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        self::store($user, $data['password'], $request->session()->getId());
        $audit->record('advisor.password_changed', orgId: $org->id(), userId: $user->id, ip: $request->ip());

        return redirect()->route('advisor.index')->with('status', __('advisor.password_saved'));
    }

    public function forgot(): View
    {
        abort_unless(AdvisorMail::ready(), 404);

        return view('auth.forgot');
    }

    /** Always the same answer, whether the address exists or not; the mail leaves after the response (net job). */
    public function send(Request $request): RedirectResponse
    {
        abort_unless(AdvisorMail::ready(), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        $broker = Password::broker();
        if ($user !== null && $broker instanceof PasswordBroker && AdvisorAccess::isAdvisor($user)) {
            $token = $broker->createToken($user);
            $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
            SendAdvisorResetLink::dispatchAfterResponse((string) $user->email, $url);
        }

        return redirect()->route('login')->with('status', __('advisor.forgot_sent'));
    }

    public function resetForm(Request $request, string $token): View
    {
        abort_unless(AdvisorMail::ready(), 404);

        return view('auth.reset', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request, AuditLog $audit): RedirectResponse
    {
        abort_unless(AdvisorMail::ready(), 404);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        if ($user === null || ! AdvisorAccess::isAdvisor($user)) {
            return back()->withErrors(['email' => __('advisor.reset_invalid')]);
        }
        $status = Password::broker()->reset(
            ['email' => $user->email, 'token' => $data['token'], 'password' => $data['password']],
            static function (User $user, string $password): void {
                self::store($user, $password, null);
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __('advisor.reset_invalid')]);
        }
        $audit->record('advisor.password_reset_by_mail', userId: $user->id, ip: $request->ip());

        return redirect()->route('login')->with('status', __('advisor.reset_done'));
    }

    /** The own password: the one-time flag goes, every other sign-in ends (the current one may stay). */
    private static function store(User $user, string $password, ?string $keepSession): void
    {
        // The `hashed` cast makes the Argon2id hash.
        $user->forceFill(['password_hash' => $password])->save();
        AdvisorAccess::query()->withoutGlobalScopes()->where('user_id', $user->id)->update(['must_change_password' => false]);
        UserSessions::endOthers($user->id, $keepSession);
        if ($keepSession === null) {
            UserSessions::rotateRememberToken($user);
        }
    }
}
