<?php

declare(strict_types=1);

namespace App\Domain\Org;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query of an org-scoped model to the active organisation
 * (PLAN §11.2 IDOR protection, D-008). Without an active organisation the
 * query fails instead of returning everything.
 *
 * @implements Scope<Model>
 */
final class OrgScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('org_id'), app(CurrentOrg::class)->id());
    }
}
