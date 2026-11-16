<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\RecentConfirmation;
use App\Domain\Auth\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `confirmed` (point 212): a sensitive action runs only within five minutes of the last confirmation.
 * Missing or stale, the action does not fail: a fetch gets 423 with `confirm: true` (header X-DocHamster-Confirm) and
 * the shared JavaScript (core/confirm.js) asks in the dialog "Bitte bestätigen" and sends the request again; a plain
 * form post goes back with its input and the dialog open. Forms with `data-confirm-sudo` are asked before sending.
 */
final class RequireRecentConfirmation
{
    public const ALIAS = 'confirmed';

    /** The routes behind it; routes/web.php adds the middleware by these names (and the test checks the list). */
    public const ROUTES = [
        'exports.store',
        'backup.store', 'backup.download',
        'documents.bulk.purge', 'documents.trash.empty',
        'roles.store', 'roles.update', 'roles.destroy', 'roles.restrict', 'roles.pause',
        'members.store', 'members.reset', 'members.revoke', 'members.role', 'members.access', 'members.pause',
        'devices.store', 'devices.rekey', 'devices.destroy',
        'sessions.end', 'sessions.others',
        'access-keys.store', 'access-keys.destroy',
        'updates.install', 'updates.install-main', 'updates.install-preview',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! RecentConfirmation::required($user instanceof User ? $user : null, $request->session())) {
            return $next($request);
        }

        if (self::fromScript($request)) {
            return response()->json([
                'confirm' => true,
                'message' => __('confirm.needed'),
            ], 423, ['X-DocHamster-Confirm' => 'required', 'Cache-Control' => 'no-store']);
        }

        // Nothing secret travels back into the form (passwords), and files can't be flashed.
        $input = Arr::where(Arr::except((array) $request->input(), ['_token']), static fn (mixed $value, string|int $key): bool => ! str_contains((string) $key, 'password'));

        return redirect()->back()->withInput($input)->with('confirm_required', true);
    }

    /** A fetch (Sec-Fetch-Mode is not "navigate"), or – without that header – a request that wants JSON. */
    private static function fromScript(Request $request): bool
    {
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $mode !== null ? $mode !== 'navigate' : $request->expectsJson();
    }
}
