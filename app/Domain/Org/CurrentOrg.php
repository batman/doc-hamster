<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * The organisation the current request (or job) works in (D-008: active org
 * in the session). Set by the ResolveCurrentOrg middleware; jobs set it from
 * their payload. Registered as a scoped binding, so it is reset per request
 * and per job.
 */
final class CurrentOrg
{
    private ?Membership $membership = null;

    public function set(Membership $membership): void
    {
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->membership = null;
    }

    public function isSet(): bool
    {
        return $this->membership !== null;
    }

    public function id(): string
    {
        return $this->membership()->org_id;
    }

    public function membership(): Membership
    {
        return $this->membership ?? throw new MissingOrgContext;
    }

    public function organization(): Organization
    {
        // Null only if the organisation was deleted mid-request – then there is no context.
        return $this->membership()->organization ?? throw new MissingOrgContext;
    }

    public function role(): Role
    {
        return $this->membership()->role;
    }

    /**
     * Org-wide answer for the Gate: true only for `Allow`, or `OrgSetting`
     * with the setting on. Cells that depend on the resource (own document,
     * share) are false here and decided by the resource policies.
     */
    public function allows(Permission $permission): bool
    {
        if (! $this->isSet()) {
            return false;
        }

        return match ($this->role()->access($permission)) {
            Access::Allow => true,
            Access::OrgSetting => $this->setting($permission),
            default => false,
        };
    }

    /**
     * Value of the ⚙️ organisation setting behind a permission (default if unset).
     */
    public function setting(Permission $permission): bool
    {
        $setting = $permission->orgSetting();

        if ($setting === null) {
            return false;
        }

        [$key, $default] = $setting;
        $value = ($this->organization()->settings_json ?? [])[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }
}
