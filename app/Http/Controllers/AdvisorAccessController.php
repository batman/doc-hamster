<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Advisor\AdvisorAccess;
use App\Domain\Advisor\AdvisorMail;
use App\Domain\Advisor\AdvisorPassword;
use App\Domain\Advisor\RecentViews;
use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Auth\UserSessions;
use App\Domain\Banking\BankAccount;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\Role;
use App\Http\ShownOnce;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Personen → Steuerberater-Zugang (point 210, AK-4.6.4, D-012): Owner/Admin creates the access
 * (user name, scope: period, accounts incl. cash boxes, optional expiry; optional e-mail). docHamster makes a strong
 * one-time password, shown once in an open dialog; on first sign-in the advisor sets his own. Reset (F29) makes a new
 * one-time password; "Zugang beenden" ends it at once. A380: "Zuletzt angesehen" from the audit trail.
 */
final class AdvisorAccessController
{
    public function index(Request $request, CurrentOrg $org): View
    {
        $token = $request->query('shown');
        $fresh = ShownOnce::get('advisor-password', $org->membership()->user_id, $token, ['username', 'password']);
        $accesses = AdvisorAccess::query()->with(['user', 'membership'])->oldest()->get()
            ->filter(static fn (AdvisorAccess $a): bool => $a->membership->isActive())->values();

        return view('settings.advisors', [
            'accesses' => $accesses,
            'accounts' => BankAccount::query()->orderByRaw("kind = 'cash'")->orderBy('name')->get(),
            'fresh' => $fresh,
            'recent' => $accesses->mapWithKeys(static fn (AdvisorAccess $a): array => [$a->id => RecentViews::of($org->id(), $a->user_id)])->all(),
            'mailReady' => AdvisorMail::ready(),
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')],
            ...self::scopeRules(),
        ]);
        $role = OrgRole::query()->where('system_key', Role::Accountant->value)->first();
        abort_if($role === null, 409);
        $password = AdvisorPassword::generate();
        $ownerId = $org->membership()->user_id;
        $access = DB::transaction(function () use ($data, $org, $role, $password, $ownerId, $request): AdvisorAccess {
            $user = User::query()->create([
                'username' => mb_strtolower($data['username']),
                'email' => isset($data['email']) && $data['email'] !== '' ? mb_strtolower($data['email']) : null,
                'display_name' => $data['username'],
                'password_hash' => $password,
            ]);
            $membership = new Membership(['org_id' => $org->id(), 'user_id' => $user->id]);
            $membership->assignRole($role);
            $membership->save();

            return AdvisorAccess::query()->create(['membership_id' => $membership->id, 'user_id' => $user->id, 'created_by' => $ownerId, 'must_change_password' => true, ...$this->scope($request)]);
        });
        $audit->record('advisor.access_created', orgId: $org->id(), userId: $ownerId, subject: $access, meta: $this->meta($access), ip: $request->ip());

        return $this->show($org, $access, $password);
    }

    public function update(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $access = $this->access($id);
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($access->user_id)],
            ...self::scopeRules(),
        ]);
        $access->update($this->scope($request));
        $access->user->forceFill(['email' => isset($data['email']) && $data['email'] !== '' ? mb_strtolower($data['email']) : null])->save();
        $audit->record('advisor.access_changed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $access, meta: $this->meta($access), ip: $request->ip());

        return redirect()->route('advisors.index')->with('status', __('advisor.saved'));
    }

    /** F29: Owner/Admin makes a new one-time password; all sign-ins of the advisor end at once. */
    public function reset(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $access = $this->access($id);
        $password = AdvisorPassword::renew($access->user, $access);
        $audit->record('advisor.password_reset', orgId: $org->id(), userId: $org->membership()->user_id, subject: $access, ip: $request->ip());

        return $this->show($org, $access, $password);
    }

    /** "Zugang beenden": the membership is revoked (the row stays for the audit trail), every sign-in ends. */
    public function revoke(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $access = $this->access($id);
        $access->membership->update(['revoked_at' => now()]);
        UserSessions::endOthers($access->user_id, null);
        UserSessions::rotateRememberToken($access->user);
        $audit->record('advisor.access_revoked', orgId: $org->id(), userId: $org->membership()->user_id, subject: $access, ip: $request->ip());

        return redirect()->route('advisors.index')->with('status', __('advisor.revoked', ['name' => $access->user->username]));
    }

    private function show(CurrentOrg $org, AdvisorAccess $access, string $password): RedirectResponse
    {
        // The one-time password at once in an open dialog – via ShownOnce, never as a session flash.
        $token = ShownOnce::put('advisor-password', $org->membership()->user_id, ['username' => (string) $access->user->username, 'password' => $password]);

        return redirect()->route('advisors.index', ['shown' => $token]);
    }

    private function access(string $id): AdvisorAccess
    {
        $access = AdvisorAccess::query()->with(['user', 'membership'])->whereKey($id)->firstOrFail();
        abort_unless($access->membership->isActive(), 404);

        return $access;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scopeRules(): array
    {
        return [
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'all_accounts' => ['nullable', 'boolean'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['string', Rule::in(BankAccount::query()->pluck('id')->all())],
        ];
    }

    /**
     * @return array{period_from: ?string, period_to: ?string, expires_at: ?string, account_ids: list<string>|null}
     */
    private function scope(Request $request): array
    {
        $date = static fn (string $name): ?string => $request->filled($name) ? (string) $request->input($name) : null;

        return [
            'period_from' => $date('period_from'),
            'period_to' => $date('period_to'),
            'expires_at' => $date('expires_at'),
            'account_ids' => $request->boolean('all_accounts') ? null : array_values(array_unique(array_map('strval', (array) $request->input('accounts', [])))),
        ];
    }

    /**
     * Audit meta: the scope, never the password.
     *
     * @return array<string, mixed>
     */
    private function meta(AdvisorAccess $access): array
    {
        return [
            'from' => $access->period_from?->toDateString(), 'to' => $access->period_to?->toDateString(),
            'accounts' => $access->account_ids === null ? 'all' : count($access->account_ids), 'expires' => $access->expires_at?->toDateString(),
        ];
    }
}
