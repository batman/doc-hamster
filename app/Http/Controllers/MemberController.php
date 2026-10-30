<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
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

    public function index(CurrentOrg $org): View
    {
        return view('members.index', [
            'memberships' => Membership::query()->with('user')->where('org_id', $org->id())->oldest()->get(),
            'selfId' => $org->membership()->user_id,
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'role' => ['required', Rule::in([Role::Member->value, Role::Accountant->value])],
            'password' => self::CONFIRM,
        ]);

        $password = self::newPassword();
        $membership = DB::transaction(static function () use ($data, $org, $password): Membership {
            $user = User::query()->create([
                'username' => mb_strtolower($data['username']),
                'display_name' => $data['display_name'],
                'password_hash' => $password,
            ]);

            return Membership::query()->create(['org_id' => $org->id(), 'user_id' => $user->id, 'role' => $data['role']]);
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

        $membership->update(['revoked_at' => now()]);
        DB::table('sessions')->where('user_id', $membership->user_id)->delete();
        $this->log($audit, $request, $org, 'org.member_revoked', $membership);

        return redirect()->route('members.index')->with('status', __('members.revoked'));
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
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $membership, meta: ['role' => $membership->role->value], ip: $request->ip());
    }
}
