<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\Banking\BankAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Point 215.3: limits bank accounts and bookings to what the role of the current request may see (accounts, period).
 * Only requests carry a restriction (ResolveCurrentOrg sets it); jobs and Admins see everything.
 *
 * @implements Scope<Model>
 */
final class RestrictedScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $restriction = app(CurrentOrg::class)->restriction();
        if ($restriction->isEmpty()) {
            return;
        }
        if ($model instanceof BankAccount) {
            $restriction->accounts($builder);
        } else {
            $restriction->bookings($builder);
        }
    }
}
