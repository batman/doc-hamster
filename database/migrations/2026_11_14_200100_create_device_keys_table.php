<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Points 152, A329, A330: upload keys for an iPhone shortcut or a program (Einstellungen → Geräte).
// Only the key's SHA-256 is stored; the key can upload and nothing else.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_keys', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 12);
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
        Schema::dropIfExists('device_keys');
    }
};
