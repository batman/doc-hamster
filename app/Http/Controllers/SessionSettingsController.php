<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\SessionDevice;
use App\Domain\Auth\User;
use App\Domain\Auth\UserSessions;
use App\Domain\Org\CurrentOrg;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Einstellungen → Mein Konto, card "Angemeldete Geräte" (points 208, 222): the person's own sign-ins, end one, end all others.
 */
final class SessionSettingsController
{
    /** Laravel's remember cookie lifetime (SessionGuard::$rememberDuration, minutes). */
    private const REMEMBER_MINUTES = 576000;

    /**
     * Rows for the card; the full IP never leaves this method (A374).
     *
     * @return list<array{id: string, name: string, mobile: bool, ip: string|null, ago: string, at: string, current: bool}>
     */
    public static function rows(Request $request, string $userId): array
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;

        return array_values(UserSessions::of($userId)->map(static function (object $row) use ($current): array {
            $parsed = SessionDevice::parse($row->user_agent);
            $last = Carbon::createFromTimestamp($row->last_activity)->setTimezone(config('app.timezone'));

            return [
                'id' => $row->id,
                'name' => self::name($parsed['browser'], $parsed['system']),
                'mobile' => SessionDevice::isMobile($row->user_agent),
                'ip' => SessionDevice::shortIp($row->ip_address),
                'ago' => $last->diffForHumans(),
                'at' => $last->format('d.m.Y H:i'),
                'current' => $row->id === $current,
            ];
        })->sortByDesc('current')->all());
    }

    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        abort_if($request->session()->getId() === $id, 403);
        abort_unless(UserSessions::end($userId, $id), 404);
        $audit->record('auth.session_ended', orgId: $org->id(), userId: $userId, ip: $request->ip());

        return redirect()->route('account.show')->with('status', __('devices.sessions.ended'));
    }

    public function destroyOthers(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $count = UserSessions::endOthers($user->id, $request->session()->getId());
        $token = UserSessions::rotateRememberToken($user);
        self::keepRememberCookie($request, $user, $token);
        $audit->record('auth.sessions_ended', orgId: $org->id(), userId: $user->id, meta: ['count' => $count], ip: $request->ip());

        return redirect()->route('account.show')->with('status', trans_choice('devices.sessions.others_ended', $count, ['count' => $count]));
    }

    /** This device keeps its "Angemeldet bleiben" cookie, now with the new token. */
    public static function keepRememberCookie(Request $request, User $user, string $token): void
    {
        $guard = Auth::guard();
        if (! $request->cookies->has($guard->getRecallerName())) {
            return;
        }
        Cookie::queue($guard->getRecallerName(), $user->id.'|'.$token.'|'.$user->getAuthPassword(), self::REMEMBER_MINUTES);
    }

    private static function name(?string $browser, ?string $system): string
    {
        return match (true) {
            $browser !== null && $system !== null => __('devices.sessions.name', ['browser' => $browser, 'system' => $system]),
            $browser !== null => $browser,
            $system !== null => $system,
            default => __('devices.sessions.unknown'),
        };
    }
}
