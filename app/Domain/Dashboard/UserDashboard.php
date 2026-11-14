<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One person's Home dashboard in one organisation (points 176, A349-A353): the widget layout and pinned places
 * (DashboardLayout) and the own photo on the private disk. Only that person reads or changes it (A353).
 *
 * @phpstan-import-type Layout from DashboardLayout
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property array<string, mixed>|null $layout
 * @property string|null $photo_path
 * @property Carbon|null $updated_at
 */
final class UserDashboard extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'user_dashboards';

    protected $fillable = ['org_id', 'user_id', 'layout', 'photo_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    /** The person's dashboard; a new person gets the default layout (A352), stored on the first change. */
    public static function of(string $userId): self
    {
        return self::query()->firstOrNew(['user_id' => $userId], ['layout' => DashboardLayout::default()]);
    }

    /** @return Layout */
    public function cleanLayout(): array
    {
        return $this->layout === null ? DashboardLayout::default() : DashboardLayout::normalize($this->layout);
    }
}
