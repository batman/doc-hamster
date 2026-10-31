<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\OrgRole;
use App\Domain\Org\RoleRight;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admins name roles and set their checkboxes (#30). The pre-installed roles
 * can be renamed and changed, but not deleted; own roles only while nobody
 * active has them.
 */
final class RoleController
{
    private const CONFIRM = ['required', 'current_password'];

    public function index(): View
    {
        return view('roles.index', [
            'roles' => OrgRole::query()
                ->withCount(['memberships' => static fn ($query) => $query->whereNull('revoked_at')])
                ->orderByRaw('system_key IS NULL')
                ->orderBy('name')
                ->get(),
            'rights' => RoleRight::cases(),
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $role = OrgRole::query()->create(['name' => $data['name'], 'rights' => self::rights($data)]);
        $this->log($audit, $request, $org, 'org.role_created', $role);

        return redirect()->route('roles.index')->with('status', __('roles.saved'));
    }

    public function update(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $role = OrgRole::query()->whereKey($id)->firstOrFail();
        $data = $request->validate($this->rules($role));

        $role->update(['name' => $data['name'], 'rights' => self::rights($data)]);
        $this->log($audit, $request, $org, 'org.role_updated', $role);

        return redirect()->route('roles.index')->with('status', __('roles.saved'));
    }

    public function destroy(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $request->validate(['password' => self::CONFIRM]);
        $role = OrgRole::query()->whereKey($id)->firstOrFail();
        abort_if($role->isPreinstalled(), 403);

        if ($role->memberships()->whereNull('revoked_at')->exists()) {
            return back()->withErrors(['role' => __('roles.in_use')]);
        }

        $this->log($audit, $request, $org, 'org.role_deleted', $role);
        $role->delete();

        return redirect()->route('roles.index')->with('status', __('roles.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?OrgRole $role = null): array
    {
        return [
            'name' => ['required', 'string', 'max:64', static function (string $attribute, mixed $value, Closure $fail) use ($role): void {
                // In PHP, so the check doesn't depend on the database collation.
                $taken = OrgRole::query()->whereKeyNot($role?->id)->pluck('name')
                    ->push(__('members.roles.owner'))
                    ->map(static fn (string $name): string => mb_strtolower($name));

                if (is_string($value) && $taken->contains(mb_strtolower($value))) {
                    $fail(__('roles.name_taken'));
                }
            }],
            'rights' => ['array'],
            'rights.*' => [Rule::enum(RoleRight::class)],
            'password' => self::CONFIRM,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function rights(array $data): array
    {
        $rights = $data['rights'] ?? [];

        return array_values(array_unique(is_array($rights) ? array_filter($rights, 'is_string') : []));
    }

    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, OrgRole $role): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $role, meta: ['name' => $role->name, 'rights' => $role->rights], ip: $request->ip());
    }
}
