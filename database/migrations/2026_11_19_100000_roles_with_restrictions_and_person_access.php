<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Point 215: no separate tax-advisor access any more – a tax advisor is a person with a role.
     * - roles: optional restrictions (period, bank accounts incl. cash boxes, folder branches; all apply together, A387)
     *   and `paused_at` (point 223, A389).
     * - memberships: optional "Zugang von / bis" (point 218) and `paused_at` for a single person.
     * - users: `must_change_password` – the password is a one-time password made by docHamster (any person).
     * Existing advisor accesses and accountant memberships become normal persons with an org role "Steuerberater";
     * a scope becomes a role restriction (one role per distinct scope), the expiry the person's "Zugang bis".
     * Runs on update without console: plain DB queries only.
     */
    public function up(): void
    {
        Schema::table('roles', static function (Blueprint $table): void {
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('account_ids')->nullable();
            $table->json('folder_ids')->nullable();
            $table->timestamp('paused_at')->nullable();
        });
        Schema::table('memberships', static function (Blueprint $table): void {
            $table->date('access_from')->nullable();
            $table->date('access_until')->nullable();
            $table->timestamp('paused_at')->nullable();
        });
        Schema::table('users', static function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
        });

        $accesses = Schema::hasTable('advisor_accesses')
            ? array_values(array_map(static fn (object $row): array => (array) $row, DB::table('advisor_accesses')->orderBy('created_at')->get()->all()))
            : [];
        $this->convert($accesses);

        Schema::dropIfExists('advisor_accesses');
    }

    /**
     * The data step (also called by the test with synthetic rows).
     *
     * @param  list<array<array-key, mixed>>  $accesses  rows of advisor_accesses
     */
    public function convert(array $accesses): void
    {
        $orgIds = DB::table('roles')->where('system_key', 'accountant')->pluck('org_id')
            ->merge(DB::table('memberships')->where('role', 'accountant')->pluck('org_id'))
            ->merge(array_column($accesses, 'org_id'))->unique()->values();

        foreach ($orgIds as $orgId) {
            $orgId = (string) $orgId;
            $base = $this->baseRole($orgId);
            $byScope = [];
            foreach ($accesses as $access) {
                if (($access['org_id'] ?? null) !== $orgId) {
                    continue;
                }
                $scope = [
                    'period_from' => self::date($access['period_from'] ?? null),
                    'period_to' => self::date($access['period_to'] ?? null),
                    'account_ids' => is_string($access['account_ids'] ?? null) ? $access['account_ids'] : null,
                ];
                if ($scope['period_from'] === null && $scope['period_to'] === null && $scope['account_ids'] === null) {
                    $roleId = $base;
                } else {
                    $key = (string) json_encode($scope);
                    $roleId = $byScope[$key] ??= $this->scopedRole($orgId, $base, $scope);
                }
                DB::table('memberships')->where('id', $access['membership_id'])->update([
                    'role' => 'member',
                    'org_role_id' => $roleId,
                    'access_until' => self::date($access['expires_at'] ?? null),
                ]);
                DB::table('users')->where('id', $access['user_id'])->update(['must_change_password' => (bool) ($access['must_change_password'] ?? false)]);
            }
            DB::table('memberships')->where('org_id', $orgId)->where('role', 'accountant')->whereNull('org_role_id')->update(['org_role_id' => $base]);
            DB::table('memberships')->where('org_id', $orgId)->where('role', 'accountant')->update(['role' => 'member']);
        }
        DB::table('invitations')->where('role', 'accountant')->update(['role' => 'member']);

        // The receipt packs needed "Exportieren" + "Alle Dokumente sehen" so far: such roles keep them as "Belegpakete".
        foreach (DB::table('roles')->get(['id', 'rights']) as $role) {
            $rights = json_decode((string) $role->rights, true);
            if (is_array($rights) && in_array('export', $rights, true) && in_array('view_all', $rights, true) && ! in_array('packs', $rights, true)) {
                DB::table('roles')->where('id', $role->id)->update(['rights' => json_encode([...$rights, 'packs'])]);
            }
        }
    }

    /** The old pre-installed "Steuerberater" becomes a normal role with "Belegpakete" (or is made). */
    private function baseRole(string $orgId): string
    {
        $role = DB::table('roles')->where('org_id', $orgId)->where('system_key', 'accountant')->first();
        if ($role !== null) {
            $rights = json_decode((string) $role->rights, true);
            $rights = is_array($rights) ? $rights : [];
            DB::table('roles')->where('id', $role->id)->update([
                'system_key' => null,
                'rights' => json_encode(array_values(array_unique([...$rights, 'view_all', 'packs']))),
                'updated_at' => now(),
            ]);

            return (string) $role->id;
        }

        return $this->insertRole($orgId, 'Steuerberater', ['view_all', 'packs'], []);
    }

    /**
     * @param  array{period_from: ?string, period_to: ?string, account_ids: ?string}  $scope
     */
    private function scopedRole(string $orgId, string $baseId, array $scope): string
    {
        $base = DB::table('roles')->where('id', $baseId)->first();
        $rights = json_decode((string) ($base->rights ?? '[]'), true);

        return $this->insertRole($orgId, (string) ($base->name ?? 'Steuerberater'), is_array($rights) ? array_values($rights) : [], $scope);
    }

    /**
     * @param  list<mixed>  $rights
     * @param  array<string, mixed>  $restrictions
     */
    private function insertRole(string $orgId, string $name, array $rights, array $restrictions): string
    {
        $taken = DB::table('roles')->where('org_id', $orgId)->pluck('name')->map(static fn (string $n): string => mb_strtolower($n))->all();
        $candidate = $name;
        for ($i = 2; in_array(mb_strtolower($candidate), $taken, true); $i++) {
            $candidate = $name.' '.$i;
        }
        $id = Str::lower((string) Str::ulid());
        DB::table('roles')->insert([
            'id' => $id, 'org_id' => $orgId, 'name' => $candidate, 'system_key' => null, 'rights' => json_encode($rights),
            ...$restrictions, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    public function down(): void
    {
        Schema::table('roles', static function (Blueprint $table): void {
            $table->dropColumn(['period_from', 'period_to', 'account_ids', 'folder_ids', 'paused_at']);
        });
        Schema::table('memberships', static function (Blueprint $table): void {
            $table->dropColumn(['access_from', 'access_until', 'paused_at']);
        });
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
