<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Owners manage who has access (D-012): they create accounts, the app makes a
 * strong random password that is shown once to pass on. No self-registration.
 * Password login for everyone is the interim until passkeys (Q-13, P1-4).
 */
final class MemberController
{
    private const CONFIRM = ['required', 'current_password'];

    /** Form value for the fixed Admin role; every other value is an OrgRole id. */
    private const ADMIN = 'owner';

    public function index(CurrentOrg $org): View
    {
        return view('members.index', [
            'memberships' => Membership::query()->with(['user', 'orgRole'])->where('org_id', $org->id())->oldest()->get(),
            'roles' => OrgRole::query()->orderByRaw('system_key IS NULL')->orderBy('name')->get(),
            'adminValue' => self::ADMIN,
            'selfId' => $org->membership()->user_id,
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'role' => self::roleRule(),
            'password' => self::CONFIRM,
        ]);

        $password = self::newPassword();
        $membership = DB::transaction(static function () use ($data, $org, $password): Membership {
            $user = User::query()->create([
                'username' => mb_strtolower($data['username']),
                'display_name' => $data['display_name'],
                'password_hash' => $password,
            ]);

            $membership = new Membership(['org_id' => $org->id(), 'user_id' => $user->id]);
            $membership->assignRole(self::chosenRole($data['role']));
            $membership->save();

            return $membership;
        });

        $this->log($audit, $request, $org, 'org.member_added', $membership);

        return redirect()->route('members.index')->with('new_password', [
            'name' => $data['display_name'],
            'username' => mb_strtolower($data['username']),
            'password' => $password,
        ]);
    }

    public function resetPassword(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $request->validate(['password' => self::CONFIRM]);
        $membership = $this->membership($id, $org);
        $user = $membership->user;
        abort_if($user === null || $membership->user_id === $org->membership()->user_id, 403);

        $password = self::newPassword();
        $user->update(['password_hash' => $password]);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->log($audit, $request, $org, 'org.member_password_reset', $membership);

        return redirect()->route('members.index')->with('new_password', [
            'name' => $user->display_name,
            'username' => (string) $user->username,
            'password' => $password,
        ]);
    }

    public function revoke(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $request->validate(['password' => self::CONFIRM]);
        $membership = $this->membership($id, $org);
        abort_if($membership->user_id === $org->membership()->user_id, 403);

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

        DB::table('sessions')->where('user_id', $membership->user_id)->delete();
        $this->log($audit, $request, $org, 'org.member_revoked', $membership);

        return redirect()->route('members.index')->with('status', __('members.revoked'));
    }

    public function changeRole(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate(['role' => self::roleRule(), 'password' => self::CONFIRM]);
        $membership = $this->membership($id, $org);
        abort_if($membership->user_id === $org->membership()->user_id || ! $membership->isActive(), 403);

        $orgRole = self::chosenRole($data['role']);
        $changed = DB::transaction(function () use ($membership, $org, $orgRole): bool {
            if ($orgRole !== null && ! $this->anotherAdminStays($membership, $org)) {
                return false;
            }
            $membership->assignRole($orgRole);
            $membership->save();

            return true;
        });

        if (! $changed) {
            return back()->withErrors(['role' => __('members.last_admin')]);
        }

        $membership->unsetRelation('orgRole');
        $this->log($audit, $request, $org, 'org.member_role_changed', $membership);

        return redirect()->route('members.index')->with('status', __('members.role_changed'));
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

    private static function chosenRole(string $value): ?OrgRole
    {
        return $value === self::ADMIN ? null : OrgRole::query()->whereKey($value)->firstOrFail();
    }

    /** Memberships aren't org-scoped by the model, so limit to the active organisation here. */
    private function membership(string $id, CurrentOrg $org): Membership
    {
        return Membership::query()->with('user')->where('org_id', $org->id())->whereKey($id)->firstOrFail();
    }

    private static function newPassword(): string
    {
        return Str::password(24, symbols: false);
    }

    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, Membership $membership): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $membership, meta: ['role' => $membership->role->value, 'role_name' => $membership->roleName()], ip: $request->ip());
    }
}
