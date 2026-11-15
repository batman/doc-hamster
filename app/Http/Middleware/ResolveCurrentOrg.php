<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\PersonAccess;
use App\Domain\Org\Role;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the active organisation for the request (D-008). It comes from the
 * session, but only if the user still has an active membership there;
 * otherwise the user's oldest active membership is used. No membership at
 * all → 403. Point 215: a paused membership or one outside "Zugang von / bis" signs out at once (also mid-session);
 * the role's restriction is set for the request; a one-time password must be replaced first.
 */
final class ResolveCurrentOrg
{
    public const SESSION_KEY = 'active_org_id';

    /** Routes a person with a one-time password may still reach. */
    private const array ONETIME_OPEN = ['account.password', 'account.password.*', 'logout', 'confirm.*', 'log.client', 'manifest', 'badges'];

    public function __construct(private readonly CurrentOrg $currentOrg) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $membership = $this->membership($user, $request->session()->get(self::SESSION_KEY));

        abort_if($membership === null, 403);

        $refusal = PersonAccess::refusal($membership);
        if ($refusal !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => $refusal], 401);
            }

            return redirect()->route('login')->withErrors(['username' => $refusal]);
        }

        $request->session()->put(self::SESSION_KEY, $membership->org_id);
        $this->currentOrg->set($membership);
        if ($membership->role !== Role::Owner) {
            $restriction = $membership->orgRole?->restriction();
            $this->currentOrg->restrictTo($restriction !== null && ! $restriction->isEmpty() ? $restriction : null);
            // A384: a role that sees only some folders has no Posteingang.
            abort_if($restriction?->hidesInbox() === true && $request->routeIs('inbox.*'), 403);
        }

        if ($user->must_change_password && Route::has('account.password') && ! $request->routeIs(...self::ONETIME_OPEN)) {
            return $request->expectsJson() ? response()->json(['message' => __('auth.onetime_first')], 403) : redirect()->route('account.password');
        }

        return $next($request);
    }

    /** The chosen organisation if usable, else the oldest usable one, else the chosen / oldest one (it is refused then). */
    private function membership(User $user, mixed $orgId): ?Membership
    {
        $active = $user->memberships()->whereNull('revoked_at')->with('orgRole')->oldest()->oldest('id')->get();
        $chosen = is_string($orgId) ? $active->firstWhere('org_id', $orgId) : null;
        if ($chosen !== null && PersonAccess::allows($chosen)) {
            return $chosen;
        }

        return $active->first(static fn (Membership $m): bool => PersonAccess::allows($m)) ?? $chosen ?? $active->first();
    }
}
