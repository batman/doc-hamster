<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\RecentViews;
use App\Domain\Auth\OneTimePassword;
use App\Domain\Auth\User;
use App\Domain\Auth\UserSessions;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\Role;
use App\Http\ShownOnce;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Personen (D-012, points 215, 218, 223) as an accordion list. Admins add a person (name, user name,
 * role, optional "Zugang von / bis"); docHamster makes a one-time password, shown once (ShownOnce), which the person
 * replaces at the first sign-in. In the open row: role (saves at once), access dates, a new one-time password,
 * "Pausiert" and "Zuletzt angesehen". Nobody acts on their own row. No self-registration.
 */
final class MemberController
{
    /** Form value for the fixed Admin role; every other value is an OrgRole id. */
    private const ADMIN = 'owner';

    public function index(Request $request, CurrentOrg $org): View
    {
        $fresh = ShownOnce::get('member-password', $org->membership()->user_id, $request->query('shown'), ['name', 'username', 'password']);
        $memberships = Membership::query()->with(['user', 'orgRole'])->where('org_id', $org->id())->whereNull('revoked_at')->oldest()->get();
        $userIds = $memberships->pluck('user_id')->all();

        return view('members.index', [
            'fresh' => $fresh !== null && $fresh['password'] !== '' ? $fresh : null,
            'memberships' => $memberships,
            'roles' => OrgRole::query()->orderByRaw('system_key IS NULL')->orderBy('name')->get(),
            'adminValue' => self::ADMIN,
            'selfId' => $org->membership()->user_id,
            'lastActive' => DB::table('sessions')->whereIn('user_id', $userIds)->groupBy('user_id')->selectRaw('user_id, max(last_activity) as at')->pluck('at', 'user_id')->all(),
            'recent' => $memberships->mapWithKeys(static fn (Membership $m): array => [$m->id => RecentViews::of($org->id(), $m->user_id)])->all(),
            'open' => is_string($request->query('open')) ? $request->query('open') : null,
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'role' => self::roleRule(),
            ...self::accessRules(),
        ]);

        $password = OneTimePassword::generate();
        $membership = DB::transaction(static function () use ($data, $org, $password): Membership {
            $user = User::query()->create([
                'username' => mb_strtolower($data['username']),
                'display_name' => $data['display_name'],
                'password_hash' => $password,
            ]);
            $user->forceFill(['must_change_password' => true])->save();

            $membership = new Membership(['org_id' => $org->id(), 'user_id' => $user->id, 'access_from' => $data['access_from'] ?? null, 'access_until' => $data['access_until'] ?? null]);
            $membership->assignRole(self::chosenRole($data['role']));
            $membership->save();

            return $membership;
        });

        $this->log($audit, $request, $org, 'org.member_added', $membership);

        return $this->show($org, $membership, $data['display_name'], mb_strtolower($data['username']), $password);
    }

    /** "Neues Einmalpasswort erzeugen": ends every sign-in of that person ("Angemeldet bleiben" included, A375). */
    public function resetPassword(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $membership = $this->other($id, $org);
        $user = $membership->user;
        abort_if($user === null, 404);

        // A378: someone restored from a backup may have lost the username (the owner took it) – give a free one.
        if ($user->username === null) {
            $user->forceFill(['username' => self::freeUsername((string) $user->display_name)])->save();
        }
        $password = OneTimePassword::renew($user);
        $this->log($audit, $request, $org, 'org.member_password_reset', $membership);

        return $this->show($org, $membership, (string) $user->display_name, (string) $user->username, $password);
    }

    /** The new password at once in an open dialog – via ShownOnce, not a flash that other requests eat. */
    private function show(CurrentOrg $org, Membership $membership, string $name, string $username, string $password): RedirectResponse
    {
        $token = ShownOnce::put('member-password', $org->membership()->user_id, ['name' => $name, 'username' => $username, 'password' => $password]);

        return redirect()->route('members.index', ['shown' => $token, 'open' => $membership->id]);
    }

    public function revoke(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $membership = $this->other($id, $org);

        $revoked = DB::transaction(function () use ($membership, $org): bool {
            if (! $this->anotherAdminStays($membership, $org)) {
                return false;
            }
            $membership->update(['revoked_at' => now()]);

            return true;
        });

        if (! $revoked) {
            return back()->withErrors(['role' => __('members.last_admin')]);
        }

        UserSessions::endOthers($membership->user_id, null);
        if ($membership->user !== null) {
            UserSessions::rotateRememberToken($membership->user);
        }
        $this->log($audit, $request, $org, 'org.member_revoked', $membership);

        return redirect()->route('members.index')->with('status', __('members.revoked'));
    }

    public function changeRole(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['role' => self::roleRule()]);
        $membership = $this->other($id, $org);

        $orgRole = self::chosenRole($data['role']);
        $changed = DB::transaction(function () use ($membership, $org, $orgRole): bool {
            if ($orgRole !== null && ! $this->anotherAdminStays($membership, $org)) {
                return false;
            }
            $membership->assignRole($orgRole);
            if ($orgRole === null) {
                // Admins are never paused or limited by dates (they could lock everyone out).
                $membership->paused_at = null;
            }
            $membership->save();

            return true;
        });

        if (! $changed) {
            return $request->expectsJson()
                ? response()->json(['message' => __('members.last_admin')], 422)
                : back()->withErrors(['role' => __('members.last_admin')]);
        }

        $membership->unsetRelation('orgRole');
        $this->log($audit, $request, $org, 'org.member_role_changed', $membership);

        return $this->saved($request, $membership, (string) __('members.role_changed'));
    }

    /** Point 218: "Zugang von / bis" – empty = unbegrenzt. */
    public function access(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(self::accessRules());
        $membership = $this->other($id, $org);
        $membership->update(['access_from' => $data['access_from'] ?? null, 'access_until' => $data['access_until'] ?? null]);
        $audit->record('org.member_access_changed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $membership,
            meta: ['from' => $data['access_from'] ?? null, 'until' => $data['access_until'] ?? null], ip: $request->ip());

        return $this->saved($request, $membership, (string) __('members.saved'));
    }

    /** Point 223: pausing one person signs them out at once; their upload keys refuse until resumed. */
    public function pause(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $membership = $this->other($id, $org);
        $paused = $request->boolean('paused');
        $membership->update(['paused_at' => $paused ? ($membership->paused_at ?? now()) : null]);
        if ($paused && $membership->user !== null) {
            UserSessions::endOthers($membership->user_id, null);
            UserSessions::rotateRememberToken($membership->user);
        }
        $audit->record($paused ? 'org.member_paused' : 'org.member_resumed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $membership, ip: $request->ip());

        return $this->saved($request, $membership, (string) __($paused ? 'members.paused_done' : 'members.resumed'));
    }

    private function saved(Request $request, Membership $membership, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'member' => ['id' => $membership->id, 'role' => $membership->roleName(), 'paused' => $membership->isPaused()]])
            : redirect()->route('members.index', ['open' => $membership->id])->with('status', $message);
    }

    /**
     * Whether an Admin can lose that role. The lock keeps two Admins from
     * removing each other at the same moment. Call inside a transaction.
     */
    private function anotherAdminStays(Membership $membership, CurrentOrg $org): bool
    {
        if ($membership->role !== Role::Owner) {
            return true;
        }

        return Membership::query()
            ->where('org_id', $org->id())
            ->where('role', Role::Owner->value)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->count() > 1;
    }

    /**
     * Admin or a role of the active organisation (OrgRole is org-scoped).
     *
     * @return list<mixed>
     */
    private static function roleRule(): array
    {
        return ['required', 'string', Rule::in([self::ADMIN, ...OrgRole::query()->pluck('id')->all()])];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function accessRules(): array
    {
        return [
            'access_from' => ['nullable', 'date_format:Y-m-d'],
            'access_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:access_from'],
        ];
    }

    private static function chosenRole(string $value): ?OrgRole
    {
        return $value === self::ADMIN ? null : OrgRole::query()->whereKey($value)->firstOrFail();
    }

    /** Another active person of this organisation (memberships aren't org-scoped by the model); never oneself. */
    private function other(string $id, CurrentOrg $org): Membership
    {
        $membership = Membership::query()->with('user')->where('org_id', $org->id())->whereNull('revoked_at')->whereKey($id)->firstOrFail();
        abort_if($membership->user_id === $org->membership()->user_id, 403);

        return $membership;
    }

    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, Membership $membership): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $membership, meta: ['role' => $membership->role->value, 'role_name' => $membership->roleName()], ip: $request->ip());
    }

    /** A free user name from a display name (A378); also suggests one in the "Person hinzufügen" row. */
    public static function freeUsername(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9._-]+/', '.', mb_strtolower(Str::ascii($name))), '.');
        $base = $base === '' ? 'mitglied' : mb_substr($base, 0, 40);
        $candidate = $base;
        for ($i = 2; User::query()->where('username', $candidate)->exists(); $i++) {
            $candidate = $base.$i;
        }

        return $candidate;
    }
}
