<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paired devices with their long-lived device token (PLAN §11.1). Only the
     * SHA-256 of the token is stored; `token_family` groups the tokens of one
     * rotation chain so a reused old token can revoke the whole family (P1-5).
     * IPs only as HMAC (PLAN §11.3).
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('user_agent', 512)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->ulid('token_family')->index();
            $table->dateTime('last_used_at')->nullable();
            $table->char('last_ip_hash', 64)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
