<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// D-040: access keys for the interface (api/v1) – a person's own key for a program or an assistant, "Nur lesen" or
// "Lesen + Verknüpfen". Only the key's SHA-256 is stored; without a key the interface stays closed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_keys', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('scope', 8);
            $table->char('key_hash', 64)->unique();
            $table->string('key_prefix', 12);
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['org_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_keys');
    }
};
