<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\UserSessions;
use App\Domain\Banking\BankAccount;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\Role;
use App\Domain\Org\RoleRight;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Personen → Rollen (#30, points 215, 216, 223) as an accordion list: a click opens a role; every
 * checkbox, the name (✓), the restrictions (period, accounts, folders) and "Pausiert" save at once (JSON, toast).
 * The pre-installed role can't be deleted, nor a role that people still have. Sensitive: all writes ask for the
 * fresh confirmation (`confirmed`).
 */
final class RoleController
{
    public function index(Request $request): View
    {
        $roles = OrgRole::query()
            ->withCount(['memberships' => static fn ($query) => $query->whereNull('revoked_at')])
            ->orderByRaw('system_key IS NULL')
            ->orderBy('name')
            ->get();

        return view('roles.index', [
            'roles' => $roles,
            'groups' => RoleRight::grouped(),
            'accounts' => BankAccount::query()->orderByRaw("kind = 'cash'")->orderBy('name')->get(),
            'folders' => Folder::flat(),
            'admins' => Membership::query()->where('org_id', app(CurrentOrg::class)->id())->where('role', Role::Owner->value)->whereNull('revoked_at')->count(),
            'open' => is_string($request->query('open')) ? $request->query('open') : null,
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate(['name' => self::nameRule()]);

        $role = OrgRole::query()->create(['name' => trim($data['name']), 'rights' => []]);
        $this->log($audit, $request, $org, 'org.role_created', $role);

        return redirect()->route('roles.index', ['open' => $role->id])->with('status', __('roles.created', ['name' => $role->name]));
    }

    /** Name and rights (autosave form of the open row). */
    public function update(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $role = OrgRole::query()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'name' => self::nameRule($role),
            'rights' => ['array'],
            'rights.*' => [Rule::enum(RoleRight::class)],
        ]);

        $role->update(['name' => trim($data['name']), 'rights' => self::strings($data['rights'] ?? [])]);
        $this->log($audit, $request, $org, 'org.role_updated', $role);

        return $this->saved($request, $role);
    }

    /** Point 215.3: period, accounts, folders – nothing ticked or empty = no restriction. */
    public function restrict(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $role = OrgRole::query()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['string', Rule::in(BankAccount::query()->pluck('id')->all())],
            'folders' => ['nullable', 'array'],
            'folders.*' => ['string', Rule::in(Folder::query()->pluck('id')->all())],
        ]);
        $accounts = self::strings($data['accounts'] ?? []);
        $folders = self::strings($data['folders'] ?? []);
        $role->update([
            'period_from' => $data['period_from'] ?? null,
            'period_to' => $data['period_to'] ?? null,
            'account_ids' => $accounts === [] ? null : $accounts,
            'folder_ids' => $folders === [] ? null : $folders,
        ]);
        $audit->record('org.role_restricted', orgId: $org->id(), userId: $org->membership()->user_id, subject: $role,
            meta: ['name' => $role->name, 'from' => $data['period_from'] ?? null, 'to' => $data['period_to'] ?? null, 'accounts' => count($accounts), 'folders' => count($folders)], ip: $request->ip());

        return $this->saved($request, $role);
    }

    /**
     * Point 223 (A389): pausing signs every person with the role out at once (sessions end, remember tokens rotate);
     * while paused, sign-in and upload keys are refused. Resuming gives everything back.
     */
    public function pause(Request $request, string $id, CurrentOrg $org, AuditLog $audit): JsonResponse|RedirectResponse
    {
        $role = OrgRole::query()->whereKey($id)->firstOrFail();
        $paused = $request->boolean('paused');
        $role->update(['paused_at' => $paused ? ($role->paused_at ?? now()) : null]);
        $people = Membership::query()->with('user')->where('org_id', $org->id())->where('org_role_id', $role->id)->whereNull('revoked_at')->get();
        if ($paused) {
            foreach ($people as $membership) {
                if ($membership->user !== null && $membership->user_id !== $org->membership()->user_id) {
                    UserSessions::endOthers($membership->user_id, null);
                    UserSessions::rotateRememberToken($membership->user);
                }
            }
        }
        $audit->record($paused ? 'org.role_paused' : 'org.role_resumed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $role,
            meta: ['name' => $role->name, 'people' => $people->count()], ip: $request->ip());

        return $this->saved($request, $role, $paused ? __('roles.paused_done', ['count' => $people->count()]) : __('roles.resumed'));
    }

    public function destroy(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $role = OrgRole::query()->whereKey($id)->firstOrFail();
        abort_if($role->isPreinstalled(), 403);

        if ($role->memberships()->whereNull('revoked_at')->exists()) {
            return back()->withErrors(['role' => __('roles.in_use')]);
        }

        $this->log($audit, $request, $org, 'org.role_deleted', $role);
        $role->delete();

        return redirect()->route('roles.index')->with('status', __('roles.deleted'));
    }

    private function saved(Request $request, OrgRole $role, ?string $message = null): JsonResponse|RedirectResponse
    {
        $message ??= (string) __('roles.saved');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'role' => ['id' => $role->id, 'name' => $role->name, 'pills' => $role->restriction()->pills(), 'paused' => $role->isPaused()]]);
        }

        return redirect()->route('roles.index', ['open' => $role->id])->with('status', $message);
    }

    /**
     * @return list<mixed>
     */
    private static function nameRule(?OrgRole $role = null): array
    {
        return ['required', 'string', 'max:64', static function (string $attribute, mixed $value, Closure $fail) use ($role): void {
            // In PHP, so the check doesn't depend on the database collation.
            $taken = OrgRole::query()->whereKeyNot($role?->id)->pluck('name')
                ->push(__('members.roles.owner'))
                ->map(static fn (string $name): string => mb_strtolower($name));

            if (is_string($value) && $taken->contains(mb_strtolower(trim($value)))) {
                $fail(__('roles.name_taken'));
            }
        }];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_unique(array_filter($values, 'is_string')));
    }

    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, OrgRole $role): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $role, meta: ['name' => $role->name, 'rights' => $role->rights], ip: $request->ip());
    }
}
