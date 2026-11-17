<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\AccessKey;
use App\Domain\Assistant\AssistantAccess;
use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\PersonAccess;
use App\Domain\Org\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The interface's door (D-040, api/v1 and the MCP address): `Authorization: Bearer dha_…` – a key from Mein Konto or
 * an assistant's access token. No session, no cookies, so no CSRF. The key acts as its person, like a sign-in would
 * (ResolveCurrentOrg): only an active, not paused membership inside its "Zugang von / bis", the role's restriction for
 * the request, not while a one-time password waits – and only while the owner keeps "Zugang für Assistenten" open.
 * 401 without a working key (for the MCP address with the hint where to sign in, RFC 9728), 403 when the person may
 * not (any more) or the door is closed.
 */
final class AuthenticateAccessKey
{
    public const ALIAS = 'api.key';

    /** The request attribute that carries the key to the controllers. */
    public const ATTRIBUTE = 'access_key';

    public function __construct(private readonly CurrentOrg $currentOrg) {}

    public function handle(Request $request, Closure $next, string $mode = 'api'): Response
    {
        $token = $request->bearerToken();
        $key = $token === null ? null : AccessKey::byKey($token);
        if ($key === null) {
            $response = response()->json(['message' => __('api.unauthorized')], 401);
            if ($mode === 'mcp') {
                $response->headers->set('WWW-Authenticate', 'Bearer resource_metadata="'.route('oauth.resource', ['path' => 'mcp']).'"'.($token === null ? '' : ', error="invalid_token"'));
            }

            return $response;
        }

        $membership = Membership::query()->where('org_id', $key->org_id)->where('user_id', $key->user_id)->whereNull('revoked_at')
            ->with(['orgRole', 'user'])->first();
        $user = $membership?->user;
        if ($membership === null || ! $user instanceof User || ! PersonAccess::allows($membership) || $user->must_change_password) {
            return response()->json(['message' => __('api.forbidden')], 403);
        }

        $this->currentOrg->set($membership);
        if (! AssistantAccess::enabled($this->currentOrg->organization())) {
            return response()->json(['message' => __('api.closed')], 403);
        }
        if ($membership->role !== Role::Owner) {
            $restriction = $membership->orgRole?->restriction();
            $this->currentOrg->restrictTo($restriction !== null && ! $restriction->isEmpty() ? $restriction : null);
        }
        // Only for this request (no session): the Gates ask the signed-in person.
        Auth::guard('web')->setUser($user);
        $key->touchUsed();
        $request->attributes->set(self::ATTRIBUTE, $key);

        return $next($request);
    }
}
