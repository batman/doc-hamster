<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the active organisation for the request (D-008). It comes from the
 * session, but only if the user still has an active membership there;
 * otherwise the user's oldest active membership is used. No membership at
 * all → 403.
 */
final class ResolveCurrentOrg
{
    public const SESSION_KEY = 'active_org_id';

    public function __construct(private readonly CurrentOrg $currentOrg) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $membership = $this->membership($user, $request->session()->get(self::SESSION_KEY));

        abort_if($membership === null, 403);

        $request->session()->put(self::SESSION_KEY, $membership->org_id);
        $this->currentOrg->set($membership);

        return $next($request);
    }

    private function membership(User $user, mixed $orgId): ?Membership
    {
        $active = $user->memberships()->whereNull('revoked_at');

        if (is_string($orgId)) {
            $chosen = (clone $active)->where('org_id', $orgId)->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $active->oldest()->oldest('id')->first();
    }
}
