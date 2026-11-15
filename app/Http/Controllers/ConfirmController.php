<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\RecentConfirmation;
use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Point 212: the dialog "Bitte bestätigen" (resources/views/auth/_confirm-dialog.blade.php, core/confirm.js).
 * `status` tells the script whether a form may go at once; `store` checks the password and starts the five minutes.
 */
final class ConfirmController
{
    public function status(Request $request, CurrentOrg $org): JsonResponse
    {
        $user = self::user($request);
        $required = RecentConfirmation::required($user, $request->session());

        return response()->json([
            'fresh' => ! $required,
            'seconds' => $required ? 0 : max(RecentConfirmation::secondsLeft($request->session()), 60),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = self::user($request);
        if (! RecentConfirmation::checkPassword($user, $data['password'])) {
            $audit->record('auth.confirm_failed', orgId: $org->isSet() ? $org->id() : null, userId: $user->id, ip: $request->ip());

            throw ValidationException::withMessages(['password' => __('confirm.wrong')]);
        }

        RecentConfirmation::mark($request->session());
        if ($request->expectsJson()) {
            return response()->json(['confirmed' => true, 'seconds' => RecentConfirmation::SECONDS]);
        }

        return redirect()->back()->with('status', __('confirm.again'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
