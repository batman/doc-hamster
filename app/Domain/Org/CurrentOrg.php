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
     * Answer for the Gate: Admins may everything, everyone else what the
     * checkboxes of their role say. No role means no rights.
     */
    public function allows(Permission $permission): bool
    {
        if (! $this->isSet()) {
            return false;
        }

        if ($this->role() === Role::Owner) {
            return true;
        }

        $right = $permission->right();

        return $right !== null && $this->membership()->orgRole?->has($right) === true;
    }
}
