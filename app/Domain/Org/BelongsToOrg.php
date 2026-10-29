<?php

declare(strict_types=1);

namespace App\Domain\Org;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * For every domain model with an `org_id` (AGENTS.md: every query is
 * org-scoped): reads only the active organisation's rows, and new rows get
 * its `org_id`. Writing a row into another organisation throws; without an
 * active organisation, rows can only be written with an explicit `org_id`
 * (setup, jobs).
 *
 * Identity tables are deliberately not scoped: memberships are listed across
 * organisations (org switcher), invitations are redeemed by token.
 *
 * @mixin Model
 */
trait BelongsToOrg
{
    public static function bootBelongsToOrg(): void
    {
        static::addGlobalScope(new OrgScope);

        static::saving(function (Model $model): void {
            $current = app(CurrentOrg::class);
            $orgId = $model->getAttribute('org_id');

            if ($orgId === null) {
                // No org given and none active → MissingOrgContext.
                $model->setAttribute('org_id', $current->id());
            } elseif ($current->isSet() && $orgId !== $current->id()) {
                throw new LogicException('Refusing to write a row of another organisation.');
            }
        });
    }
}
