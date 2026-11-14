<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Advisor\AdvisorAccess;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps the tax advisor in his read-only view (point 210, PLAN §11.1 point 8). Called by ResolveCurrentOrg once the
 * organisation is known: an accountant membership only reaches the advisor routes (and a few harmless ones the
 * layout needs); a one-time password must be replaced first; an expired access or a session older than 8 hours
 * signs out.
 */
final class AdvisorGate
{
    /** The session key of the sign-in time (8 hours, no long-lived device token). */
    public const string SINCE = 'advisor_since';

    public const int HOURS = 8;

    /** Routes the advisor's pages need besides his own. */
    private const array OPEN = ['advisor.*', 'logout', 'badges', 'log.client', 'tags.css', 'manifest', 'ocr.status', 'queue.tick'];

    public static function check(Request $request, Membership $membership): ?RedirectResponse
    {
        if ($membership->role !== Role::Accountant) {
            return null;
        }
        $access = AdvisorAccess::forMembership($membership);
        // The role given on the people page (no access row) keeps reading in the main app, read-only (Q-20).
        if ($access === null) {
            return null;
        }
        $since = $request->session()->get(self::SINCE);
        if (! is_int($since)) {
            $request->session()->put(self::SINCE, $since = now()->getTimestamp());
        }
        if ($access->isExpired() || $since < now()->subHours(self::HOURS)->getTimestamp()) {
            $message = $access->isExpired()
                ? __('advisor.expired', ['date' => $access->expires_at?->format('d.m.Y')])
                : __('advisor.session_over');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => $message]);
        }
        if ($access->must_change_password && ! $request->routeIs('advisor.password', 'advisor.password.update', 'logout')) {
            return redirect()->route('advisor.password');
        }
        if (! $request->routeIs(...self::OPEN)) {
            return redirect()->route('advisor.index');
        }

        return null;
    }
}
