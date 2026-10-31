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
     * Roles per organisation with checkboxes (#30). Existing people keep what
     * they could do: employees get the old matrix incl. its two organisation
     * settings, not the wider defaults of a new organisation. Admins
     * (`memberships.role = owner`) have no `org_role_id`.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('system_key', 16)->nullable();
            $table->json('rights');
            $table->datetimes();

            $table->unique(['org_id', 'name']);
            $table->unique(['org_id', 'system_key']);
        });

        Schema::table('memberships', function (Blueprint $table): void {
            // A role is only deleted while no active person has it; revoked rows lose the link.
            $table->foreignUlid('org_role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
        });

        $this->addRolesToExistingOrganizations();
    }

    public function addRolesToExistingOrganizations(): void
    {
        foreach (DB::table('organizations')->get(['id', 'settings_json']) as $organization) {
            $settings = json_decode((string) $organization->settings_json, true);
            $settings = is_array($settings) ? $settings : [];

            $employee = ['upload', 'edit'];
            if (($settings['members_see_all_documents'] ?? false) === true) {
                $employee[] = 'view_all';
            }
            if (($settings['members_may_trash_own_documents'] ?? true) === true) {
                $employee[] = 'delete';
            }

            $this->addRole($organization->id, 'member', 'Mitarbeiter', $employee);
            $this->addRole($organization->id, 'accountant', 'Steuerberater', ['view_all', 'export']);
        }
    }

    /**
     * @param  list<string>  $rights
     */
    private function addRole(string $orgId, string $key, string $name, array $rights): void
    {
        $id = Str::lower((string) Str::ulid());

        DB::table('roles')->insert([
            'id' => $id,
            'org_id' => $orgId,
            'name' => $name,
            'system_key' => $key,
            'rights' => json_encode($rights),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->where('org_id', $orgId)->where('role', $key)->update(['org_role_id' => $id]);
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('org_role_id');
        });

        Schema::dropIfExists('roles');
    }
};
