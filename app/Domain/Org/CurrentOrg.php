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

    private ?RoleRestriction $restriction = null;

    private bool $assistant = false;

    /** Setting a membership drops any restriction: jobs and the upload API see everything of the organisation. */
    public function set(Membership $membership): void
    {
        $this->membership = $membership;
        $this->restriction = null;
    }

    public function clear(): void
    {
        $this->membership = null;
        $this->restriction = null;
    }

    /** Point 215.3: the request's role restriction (ResolveCurrentOrg); Admins never have one. */
    public function restrictTo(?RoleRestriction $restriction): void
    {
        $this->restriction = $restriction;
    }

    public function restriction(): RoleRestriction
    {
        return $this->restriction ?? new RoleRestriction;
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

    /**
     * A69–A71: only the owner sees private folders and their documents; every other role (also the tax advisor) never,
     * and no assistant either – not even with the owner's rights (a request with an assistant's access token).
     */
    public function seesPrivate(): bool
    {
        return $this->isSet() && $this->role() === Role::Owner && ! $this->assistant;
    }

    /** A70: this request comes from an assistant (its access token) – it never sees private folders. */
    public function asAssistant(): void
    {
        $this->assistant = true;
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
        // Previews are a step above updating: both boxes are needed.
        if ($permission === Permission::InstallPreviews && ! $this->allows(Permission::UpdateSystem)) {
            return false;
        }

        // F40: "Bank" (import, edit, match) includes "Bank ansehen".
        if ($permission === Permission::ViewBanking && $this->allows(Permission::ImportBanking)) {
            return true;
        }

        return $right !== null && $this->membership()->orgRole?->has($right) === true;
    }
}
