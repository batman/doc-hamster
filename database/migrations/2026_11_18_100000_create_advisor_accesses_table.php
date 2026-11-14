<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point 210 (AK-4.6.4, D-012): the tax advisor's access – one row per accountant membership. Scope: period (open
     * ends allowed), the bank accounts (null = all, cash boxes included) and an optional expiry date.
     * `must_change_password`: the password was made by docHamster (one-time); the advisor sets his own first.
     * `password_reset_tokens` is Laravel's table for "Passwort vergessen?" (accountants with an e-mail only).
     */
    public function up(): void
    {
        Schema::create('advisor_accesses', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->unique()->constrained('memberships')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('account_ids')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('must_change_password')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetimes();

            $table->index(['org_id', 'user_id']);
        });

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', static function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_accesses');
        Schema::dropIfExists('password_reset_tokens');
    }
};
