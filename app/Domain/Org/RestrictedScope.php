<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\Banking\BankAccount;
use App\Domain\Documents\Folder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Point 215.3: limits bank accounts and bookings to what the role of the current request may see (accounts, period),
 * F39: folders to the role's branches and the path to them.
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
        $org = app(CurrentOrg::class);
        // A69, A71: private folders (and their subfolders) exist only for the owner – for every role, whatever it ticked.
        if ($model instanceof Folder && $org->isSet() && ! $org->seesPrivate()) {
            $private = Folder::privateBranch();
            if ($private !== []) {
                $builder->whereNotIn($model->qualifyColumn('id'), $private);
            }
        }
        $restriction = $org->restriction();
        if ($restriction->isEmpty()) {
            return;
        }
        if ($model instanceof Folder) {
            $restriction->folders($builder);
        } elseif ($model instanceof BankAccount) {
            $restriction->accounts($builder);
        } else {
            $restriction->bookings($builder);
        }
    }
}
