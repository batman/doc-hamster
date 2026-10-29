<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only org-scoped model on the `invitations` table – no real domain
 * table uses BelongsToOrg yet (documents etc. come in Phase 2).
 */
final class ScopedInvitation extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'invitations';

    protected $guarded = [];
}
