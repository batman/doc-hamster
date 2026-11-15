<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Auth\UserSessions;
use App\Domain\Org\CurrentOrg;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Einstellungen → Mein Konto (point 222): every person's own name, password and sign-ins. No permission needed –
 * everything here acts on the signed-in person only.
 *
 * One-time password (point 222.4): whoever signed in with one is sent to `account.password` and sets an own password
 * there; the current password field then takes the one-time password. The forced redirect marks the session with
 * ONETIME (or passes `forced`), a saved password clears it.
 */
final class AccountController
{
    /** Session flag: this sign-in used a one-time password, an own password is due. */
    public const ONETIME = 'account.onetime_password';

    public function show(Request $request, CurrentOrg $org): View
    {
        return $this->page($request, $org, (bool) $request->session()->get(self::ONETIME, false));
    }

    /** The redirect target for a sign-in with a one-time password (the same page, password card in that mode). */
    public function password(Request $request, CurrentOrg $org): View
    {
        return $this->page($request, $org, (bool) $request->session()->get(self::ONETIME, false));
    }

    /** Click-to-edit of the display name; saves at once. */
    public function name(Request $request): JsonResponse|RedirectResponse
    {
        $user = self::user($request);
        if (! $request->has('display_name')) {
            $request->merge(['display_name' => $request->input('value')]);
        }
        $data = $request->validate(['display_name' => ['required', 'string', 'max:100']]);
        $name = trim($data['display_name']);
        $user->forceFill(['display_name' => $name])->save();

        return $request->expectsJson() ? response()->json(['ok' => true, 'value' => $name]) : redirect()->route('account.show');
    }

    /**
     * A new own password (A375): every other sign-in of this person ends and the remember token rotates; this
     * device stays signed in and keeps its "Angemeldet bleiben" cookie with the new token.
     */
    public function updatePassword(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $user = self::user($request);
        $rules = ['password' => ['required', 'string', 'max:200', 'confirmed', PasswordRule::min(12)]];
        // Somebody without a password yet (no sign-in password so far) sets a first one; there is nothing to check.
        if ($user->hasPassword()) {
            $rules['current'] = ['required', 'string', 'current_password'];
            $rules['password'][] = 'different:current';
        }
        $data = $request->validate($rules);

        // The `hashed` cast makes the Argon2id hash.
        $user->forceFill(['password_hash' => $data['password']])->save();
        $count = UserSessions::endOthers($user->id, $request->session()->getId());
        $token = UserSessions::rotateRememberToken($user);
        SessionSettingsController::keepRememberCookie($request, $user, $token);
        $request->session()->forget(self::ONETIME);
        $audit->record('auth.password_changed', orgId: $org->id(), userId: $user->id, meta: ['sessions_ended' => $count], ip: $request->ip());

        return redirect()->route('account.show')->with('status', __('account.password_saved'));
    }

    private function page(Request $request, CurrentOrg $org, bool $forced): View
    {
        $user = self::user($request);

        return view('settings.account', [
            'user' => $user,
            'forced' => $forced,
            'sessions' => SessionSettingsController::rows($request, $org->membership()->user_id),
        ]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
