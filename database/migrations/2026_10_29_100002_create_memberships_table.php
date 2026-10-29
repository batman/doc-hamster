<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which user belongs to which organisation, in which role
     * (`owner`/`member`/`accountant`, App\Domain\Org\Role). A revoked
     * membership keeps its row (`revoked_at`) for the audit trail; one row per
     * user and organisation.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->dateTime('revoked_at')->nullable();
            $table->datetimes();

            $table->unique(['org_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
