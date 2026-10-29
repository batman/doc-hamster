<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People who use DocHamster (PLAN §6.1, D-012). Owners and members sign in
     * with passkeys + device token only, so `username` and `password_hash` stay
     * null for them; tax advisors get a username and an Argon2id password hash
     * set by the owner. Date columns are DATETIME (UTC), not TIMESTAMP, so they
     * work beyond 2038.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('username', 64)->nullable()->unique();
            $table->string('email', 254)->nullable()->unique();
            $table->string('display_name', 100);
            $table->string('locale', 5)->default('de');
            $table->string('password_hash')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
